<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Application\DTOs\DocumentGlResidualPlan;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\GlResidualRefusal;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\JournalEntryCreated;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Domain\Exceptions\UnpostableDocumentGlException;
use App\Modules\Accounting\Domain\Exceptions\UnreversibleDocumentGlException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\DoubleEntryValidator;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Shared\Contracts\Accounting\DocumentGlPreflightInterface;
use App\Shared\Contracts\Accounting\DocumentGlReversalInterface;
use App\Shared\Contracts\AccountingServiceInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Application service for accounting operations.
 *
 * Exposes accounting functionality to other modules through the AccountingServiceInterface.
 */
final class AccountingService implements AccountingServiceInterface, DocumentGlPreflightInterface, DocumentGlReversalInterface
{
    /**
     * `journal_entries.source_type` for an entry written BY posting a document.
     */
    public const DOCUMENT_SOURCE_TYPE = 'Document';

    /**
     * `journal_entries.source_type` for the entry that REVERSES a document's GL
     * when the document is withdrawn. Deliberately distinct from
     * {@see self::DOCUMENT_SOURCE_TYPE} so the two can never be confused for one
     * another — the reversal must not itself look like a posting to be reversed.
     */
    public const DOCUMENT_CANCELLATION_SOURCE_TYPE = 'DocumentCancellation';

    public function __construct(
        private readonly GeneralLedgerHashService $hashService,
        private readonly PartnerBalanceService $partnerBalanceService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly DoubleEntryValidator $doubleEntryValidator,
    ) {}

    /**
     * The scale to do a DOCUMENT's GL arithmetic at.
     *
     * CLAUDE.md rule 19: pass the ENTITY's currency, never a bare no-arg
     * `getScale()`. The document-sourced GL paths run from a post-commit listener
     * that would become queued the moment anyone makes it `ShouldQueue`, and a
     * no-arg resolve throws with no bound `CompanyContext` (gate finding N-8).
     */
    private function documentScale(Document $document): int
    {
        return $this->scaleResolver->getScaleSafe($document->currency, 3);
    }

    /**
     * W-6 D1a — the balance PRE-FLIGHT, called BEFORE a document is sealed.
     *
     * Gate finding C-1: asserting balance from inside the post-commit listener
     * meant a refusal left the document `Posted` + hash-chained with no GL at all,
     * unrecoverable because `DocumentPostingService::post()` returns early on an
     * already-posted document so `InvoicePosted` never re-fires. Running the same
     * arithmetic inside the posting transaction turns that into a clean 422 on an
     * UNSEALED document.
     *
     * @throws UnpostableDocumentGlException
     */
    public function assertDocumentGlIsPostable(Document $document): void
    {
        if (! in_array($document->type, [DocumentType::Invoice, DocumentType::CreditNote], true)) {
            return;
        }

        $plan = $this->residualPlan($document, $this->documentScale($document));

        if ($plan->refusal !== null) {
            throw UnpostableDocumentGlException::forDocument(
                $plan->refusal,
                $document->document_number ?? $document->id,
                $plan->residual,
            );
        }
    }

    /**
     * Compute — from the document alone — what the GL posting will look like and
     * whether it can balance.
     *
     * Used TWICE on purpose: by the pre-flight above (inside the posting
     * transaction, before the seal) and by the two posting methods (in the
     * post-commit listener). Deriving the numbers separately is how the two could
     * drift, which is exactly the class of defect D1a is.
     *
     * `residual = total − Σline_total − Σ(positive per-rate recomputed VAT)`.
     *
     * Verdict (orchestrator ruling, 2026-08-05):
     * - `residual < 0` → REFUSE. The credit side over-runs the AR debit; the header
     *   understates its own lines. Never a rounding artefact.
     * - `residual > 0` → book it to an absorbing account.
     *   - `SalesStampDutyPayable` when the chart defines it (Tunisia): ANY positive
     *     residual, unchanged behaviour — the timbre is a real document-level
     *     charge and is legitimately far larger than rounding.
     *   - otherwise the `SalesRoundingDifference*` account, but only up to
     *     {@see roundingTolerance()}. A chart with no document-level charge concept
     *     has no honest reason for a bigger gap, and burying real money in a
     *     rounding account would be a silent misstatement of income — worse than
     *     refusing (gate ruling: refuse, do NOT absorb-with-alert).
     *   - no absorbing account at all → REFUSE rather than silently drop it.
     * - `residual == 0` → nothing to book.
     *
     * Q1 (2026-08-07 expert-comptable ruling) — for a CREDIT NOTE only, the
     * residual above is computed against an AR-FACING total that already
     * excludes the credit note's OWN `stamp_duty_amount`: "Le compte client
     * (411) ne doit être diminué que du montant crédité hors timbre, et le
     * timbre de l'avoir doit être comptabilisé séparément comme une charge
     * fiscale pour l'entreprise." The stamp itself is resolved and booked as a
     * SEPARATE self-balancing pair ({@see DocumentGlResidualPlan::$stampExpenseAccount}
     * / {@see DocumentGlResidualPlan::$stampPayableAccount}), refused
     * (`GlResidualRefusal::NoCreditNoteStampAccount`) when the chart cannot
     * represent it. Invoices are UNCHANGED — Q1 does not touch invoice
     * treatment, and the customer legitimately owes an invoice's own timbre.
     * docs/superpowers/tickets/2026-08-06-expert-comptable-rulings-q2-q3.md §Q1
     */
    private function residualPlan(Document $document, int $scale): DocumentGlResidualPlan
    {
        // A document with NO lines has no revenue side at all: the posting writes a
        // lone AR leg and `residual == total`, which is not a rounding artefact and
        // not the D1a defect either — it is a separate, PRE-EXISTING broken shape.
        // It is unreachable through the documented API (`CreateDocumentRequest`
        // requires `lines` min:1) and survives only in legacy/test fixtures, so this
        // lane leaves its OUTCOME byte-identical to before the lane rather than
        // change ~35 call sites under a merge gate. Ticketed:
        // docs/superpowers/tickets/2026-08-05-lineless-document-gl-posting.md
        //
        // "Byte-identical" means resolving the absorbing account exactly as the old
        // inline step-3b did — `SalesStampDutyPayable` ONLY, write the leg when it
        // resolves, write nothing when it does not, and NEVER refuse:
        //   - Tunisian chart  -> the whole total is swept into 4375. Nonsense, but
        //                        balanced; that is what it did before.
        //   - any other chart -> a one-legged entry, as before.
        // It deliberately does NOT fall back to `SalesRoundingDifference*`: booking
        // an entire invoice total as an "écart d'arrondi" would be a silent
        // misstatement, and inventing new behaviour here is the ticket's job.
        // `balanceAssertable = false` keeps both guards off this shape.
        if ($document->lines->isEmpty()) {
            /** @var numeric-string $total */
            $total = (string) ($document->total ?? '0');

            $absorbing = bccomp($total, '0', $scale) > 0
                ? Account::findByPurpose($document->company_id, SystemAccountPurpose::SalesStampDutyPayable)
                : null;

            return new DocumentGlResidualPlan('0', '0', $total, [], $absorbing, null, false);
        }

        $isCreditNote = $document->type === DocumentType::CreditNote;

        /** @var numeric-string $revenue */
        $revenue = '0';
        foreach ($document->lines as $line) {
            /** @var numeric-string $lineTotal */
            $lineTotal = $line->line_total ?? '0';
            $revenue = bcadd($revenue, $lineTotal, $scale);
        }

        $taxByRate = $this->groupTaxByRate($document->lines, $scale);

        /** @var numeric-string $vat */
        $vat = '0';
        foreach ($taxByRate as $amount) {
            // Mirror the posting: only a POSITIVE bucket becomes a VAT leg.
            if (bccomp($amount, '0', $scale) > 0) {
                $vat = bcadd($vat, $amount, $scale);
            }
        }

        /** @var numeric-string $documentTotal */
        $documentTotal = (string) ($document->total ?? '0');

        // Q1 — only a CREDIT NOTE's own stamp duty is peeled out here; an
        // invoice's `stampDutyAmount` stays `'0'` and `$arFacingTotal` stays the
        // plain `$documentTotal`, so nothing below this line changes for
        // invoices.
        /** @var numeric-string $stampDutyAmount */
        $stampDutyAmount = $isCreditNote ? (string) ($document->stamp_duty_amount ?? '0') : '0';
        $hasStampDuty = bccomp($stampDutyAmount, '0', $scale) > 0;

        /** @var numeric-string $arFacingTotal */
        $arFacingTotal = $hasStampDuty
            ? bcsub($documentTotal, $stampDutyAmount, $scale)
            : $documentTotal;

        /** @var numeric-string $residual */
        $residual = bcsub($arFacingTotal, bcadd($revenue, $vat, $scale), $scale);

        $comparison = bccomp($residual, '0', $scale);

        $absorbingAccount = null;
        $refusal = null;

        if ($comparison < 0) {
            $refusal = GlResidualRefusal::NegativeResidual;
        } elseif ($comparison > 0) {
            $stampDutyAccount = Account::findByPurpose($document->company_id, SystemAccountPurpose::SalesStampDutyPayable);
            if ($stampDutyAccount !== null) {
                $absorbingAccount = $stampDutyAccount;
            } else {
                $roundingPurpose = $isCreditNote
                    ? SystemAccountPurpose::SalesRoundingDifferenceExpense
                    : SystemAccountPurpose::SalesRoundingDifferenceIncome;
                $roundingAccount = Account::findByPurpose($document->company_id, $roundingPurpose);

                if ($roundingAccount === null) {
                    $refusal = GlResidualRefusal::NoAbsorbingAccount;
                } elseif (bccomp($residual, $this->roundingTolerance($document, $scale), $scale) > 0) {
                    $refusal = GlResidualRefusal::ResidualExceedsRoundingTolerance;
                } else {
                    $absorbingAccount = $roundingAccount;
                }
            }
        }

        // Q1 — the credit note's own stamp, self-balancing pair. Only evaluated
        // when the plan is otherwise postable: a document with a MORE
        // fundamental defect (negative residual, no rounding home) should
        // surface THAT refusal, not have it masked by a stamp-account gap.
        $stampExpenseAccount = null;
        $stampPayableAccount = null;

        if ($refusal === null && $hasStampDuty) {
            $stampExpenseAccount = Account::findByPurpose($document->company_id, SystemAccountPurpose::PurchaseStampDuty);
            $stampPayableAccount = Account::findByPurpose($document->company_id, SystemAccountPurpose::SalesStampDutyPayable);

            if ($stampExpenseAccount === null || $stampPayableAccount === null) {
                $refusal = GlResidualRefusal::NoCreditNoteStampAccount;
                $stampExpenseAccount = null;
                $stampPayableAccount = null;
            }
        }

        return new DocumentGlResidualPlan(
            $revenue,
            $vat,
            $residual,
            $taxByRate,
            $absorbingAccount,
            $refusal,
            true,
            $stampDutyAmount,
            $stampExpenseAccount,
            $stampPayableAccount,
        );
    }

    /**
     * The ceiling this code accepts as "explainable by per-line tax truncation":
     * `lineCount` units of the last place.
     *
     * `groupTaxByRate()` truncates each line's tax (`bcmul(..., $scale)`), while
     * `TaxCalculationService` accumulates at `scale+1` and truncates once per rate
     * bucket. Since `Σ trunc(xᵢ) <= trunc(Σ xᵢ)`, the TIGHT bound is `(n − 1)` ULP.
     * `n` is a DELIBERATE ONE-ULP MARGIN over that derivation, not the derivation
     * itself — it costs at most one extra unit of the last place of tolerance and
     * buys immunity to an off-by-one in the bound if either rounding site changes.
     * Ruled acceptable by the fiscal-pos gate, 2026-08-05.
     *
     * @return numeric-string
     */
    private function roundingTolerance(Document $document, int $scale): string
    {
        $lineCount = $document->lines->count();

        /** @var numeric-string $ulp */
        $ulp = bcdiv('1', bcpow('10', (string) $scale), $scale);

        /** @var numeric-string $tolerance */
        $tolerance = bcmul((string) $lineCount, $ulp, $scale);

        return $tolerance;
    }

    /**
     * Defence in depth — refuse to seal a journal entry whose Σdebits != Σcredits.
     *
     * Runs after every leg is written and BEFORE the fiscal hash is computed, so
     * the throw rolls the journal-entry transaction back. With the pre-flight in
     * `DocumentPostingService` this is unreachable by construction: it can only
     * fire on a true bug (a leg written that the plan did not predict), and by then
     * the document IS already sealed — which is why it stays an unmapped
     * `RuntimeException` (a 500 + alert), never a 422.
     *
     * Uses `isSumBalanced()` — Σdr == Σcr and nothing else — rather than
     * `isBalanced()`, whose `count($lines) < 2` clause is a second, unadvertised
     * rejection rule that a zero-line or zero-total document would trip (gate
     * finding I-5). The scale is the document's, not a no-arg context resolve.
     *
     * @throws UnbalancedJournalEntryException
     */
    private function assertLegsBalance(
        JournalEntry $entry,
        string $entryType,
        Document $document,
        int $scale,
        DocumentGlResidualPlan $plan,
    ): void {
        if (! $plan->balanceAssertable) {
            return;
        }

        /** @var array<int, array{debit: string, credit: string}> $lines */
        $lines = $entry->lines
            ->map(static fn (JournalLine $line): array => [
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
            ])
            ->values()
            ->all();

        if ($this->doubleEntryValidator->isSumBalanced($lines, $scale)) {
            return;
        }

        /** @var numeric-string $totalDebits */
        $totalDebits = '0';
        /** @var numeric-string $totalCredits */
        $totalCredits = '0';
        foreach ($lines as $line) {
            /** @var numeric-string $debit */
            $debit = $line['debit'];
            /** @var numeric-string $credit */
            $credit = $line['credit'];
            $totalDebits = bcadd($totalDebits, $debit, $scale);
            $totalCredits = bcadd($totalCredits, $credit, $scale);
        }

        throw UnbalancedJournalEntryException::forSourceDocument(
            $entryType,
            $document->document_number ?? $entry->entry_number,
            $totalDebits,
            $totalCredits,
        );
    }

    /**
     * Find an account ID by code.
     *
     * @return string|null Account ID or null if not found
     */
    public function findAccountIdByCode(
        string $tenantId,
        string $companyId,
        string $code
    ): ?string {
        $account = Account::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        return $account?->id;
    }

    /**
     * Create GL entries for a posted invoice.
     *
     * Creates journal entries with:
     * - Debit: Accounts Receivable (AR)
     * - Credit: Revenue (by line item tax category)
     * - Credit: Tax Payable (by tax rate)
     *
     * @return string The journal entry ID
     */
    public function createInvoiceGLEntries(Document $invoice): string
    {
        $scale = $this->documentScale($invoice);
        $plan = $this->residualPlan($invoice, $scale);

        $entryId = DB::transaction(function () use ($invoice, $scale, $plan): string {
            $entryNumber = $this->sourceEntryNumber('INV', $invoice);

            // Get hash chain data BEFORE creating entry
            $previousHash = JournalEntry::getLastChainHash($invoice->company_id);
            $chainSequence = JournalEntry::getNextChainSequence($invoice->company_id);

            $entry = JournalEntry::create([
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'entry_number' => $entryNumber,
                'entry_date' => $invoice->document_date,
                'description' => 'Invoice '.$invoice->document_number,
                'status' => JournalEntryStatus::Posted,
                'source_type' => self::DOCUMENT_SOURCE_TYPE,
                'source_id' => $invoice->id,
                'chain_sequence' => $chainSequence,
                'previous_hash' => $previousHash,
            ]);

            // Find required accounts via SystemAccountPurpose
            $arAccount = $this->findAccountByPurpose(
                $invoice->company_id,
                SystemAccountPurpose::CustomerReceivable
            );
            $productRevenueAccount = $this->findAccountByPurpose(
                $invoice->company_id,
                SystemAccountPurpose::ProductRevenue
            );
            $serviceRevenueAccount = $this->findAccountByPurpose(
                $invoice->company_id,
                SystemAccountPurpose::ServiceRevenue
            );
            $vatCollectedAccount = $this->findAccountByPurpose(
                $invoice->company_id,
                SystemAccountPurpose::VatCollected
            );

            // 1. Create AR debit line (full invoice total)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $arAccount->id,
                'partner_id' => $invoice->partner_id,
                'debit' => $invoice->total,
                'credit' => '0',
                'description' => 'AR from Invoice '.$invoice->document_number,
            ]);

            // 2. Create Revenue credit lines (one per invoice line)
            foreach ($invoice->lines as $line) {
                // Determine revenue account based on product/service type
                $revenueAccount = $this->getRevenueAccountForLine(
                    $line,
                    $productRevenueAccount,
                    $serviceRevenueAccount
                );

                /** @var numeric-string $lineTotal */
                $lineTotal = $line->line_total ?? '0';
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $revenueAccount->id,
                    'debit' => '0',
                    'credit' => $lineTotal,
                    'description' => 'Revenue from Invoice '.$invoice->document_number.' - Line '.$line->line_number,
                ]);
            }

            // 3. Create VAT credit lines (grouped by tax rate)
            foreach ($plan->taxByRate as $rate => $amount) {
                if (bccomp($amount, '0', $scale) > 0) {
                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $vatCollectedAccount->id,
                        'debit' => '0',
                        'credit' => $amount,
                        'description' => 'VAT '.$rate.'% from Invoice '.$invoice->document_number,
                    ]);
                }
            }

            // 3b. The residual — total − revenue − line VAT — rides in the AR debit
            // and needs a credit leg or the entry cannot balance. It carries the
            // document-level Tunisian timbre (which must reach the dedicated 4375
            // liability, never the VAT account, so it is remitted to the State) and,
            // on every chart, the per-line tax truncation difference. `residualPlan()`
            // decided where it goes; a residual it refused never reaches here because
            // `DocumentPostingService` pre-flighted the same plan before sealing.
            if ($plan->absorbingAccount !== null) {
                $isStampDuty = $plan->absorbingAccount->system_purpose === SystemAccountPurpose::SalesStampDutyPayable;
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $plan->absorbingAccount->id,
                    'debit' => '0',
                    'credit' => $plan->residual,
                    'description' => ($isStampDuty ? 'Stamp duty (timbre)' : 'Tax rounding difference')
                        .' from Invoice '.$invoice->document_number,
                ]);
            }

            // 4. Calculate and set fiscal_hash AFTER lines are created
            $freshEntry = $entry->fresh(['lines']);
            if ($freshEntry === null) {
                throw new \RuntimeException('Failed to reload journal entry after creation');
            }

            // 4a. W-6 D1a — defence in depth. The entry was created `Posted` and is
            // hash-chained on the next line; `verifyChain()` checks linkage and hash
            // recomputation but NEVER the balance invariant, so an unbalanced entry
            // would pass compliance verification forever. `DocumentPostingService`
            // already refused this document pre-seal if the plan said it could not
            // balance, so this can only fire on a true bug.
            $this->assertLegsBalance($freshEntry, 'invoice', $invoice, $scale, $plan);

            $hash = $this->hashService->calculateHash($freshEntry, $previousHash);
            $entry->update(['fiscal_hash' => $hash]);

            // Dispatch JournalEntryCreated event for audit trail
            $entry = $entry->fresh(['lines']);
            if ($entry === null) {
                throw new \RuntimeException('Failed to reload journal entry after hash update');
            }

            $this->dispatchJournalEntryCreatedEvent($entry, 'invoice', $scale);

            return $entry->id;
        });

        $this->refreshPartnerBalanceAfterGlPersistence(
            $invoice->company_id,
            $invoice->partner_id,
            $entryId
        );

        return $entryId;
    }

    /**
     * Create GL reversal entries for a posted credit note.
     *
     * Creates journal entries that reverse the original invoice GL entries:
     * - Debit: Revenue (by line item tax category)
     * - Debit: Tax Payable (by tax rate)
     * - Credit: Accounts Receivable (AR)
     *
     * @return string The journal entry ID
     */
    public function createCreditNoteGLEntries(Document $creditNote): string
    {
        $scale = $this->documentScale($creditNote);
        $plan = $this->residualPlan($creditNote, $scale);

        $entryId = DB::transaction(function () use ($creditNote, $scale, $plan): string {
            $entryNumber = $this->sourceEntryNumber('CN', $creditNote);

            // Get hash chain data BEFORE creating entry
            $previousHash = JournalEntry::getLastChainHash($creditNote->company_id);
            $chainSequence = JournalEntry::getNextChainSequence($creditNote->company_id);

            $entry = JournalEntry::create([
                'tenant_id' => $creditNote->tenant_id,
                'company_id' => $creditNote->company_id,
                'entry_number' => $entryNumber,
                'entry_date' => $creditNote->document_date,
                'description' => 'Credit Note '.$creditNote->document_number,
                'status' => JournalEntryStatus::Posted,
                'source_type' => self::DOCUMENT_SOURCE_TYPE,
                'source_id' => $creditNote->id,
                'chain_sequence' => $chainSequence,
                'previous_hash' => $previousHash,
            ]);

            // Find required accounts via SystemAccountPurpose
            $arAccount = $this->findAccountByPurpose(
                $creditNote->company_id,
                SystemAccountPurpose::CustomerReceivable
            );
            $productRevenueAccount = $this->findAccountByPurpose(
                $creditNote->company_id,
                SystemAccountPurpose::ProductRevenue
            );
            $serviceRevenueAccount = $this->findAccountByPurpose(
                $creditNote->company_id,
                SystemAccountPurpose::ServiceRevenue
            );
            $vatCollectedAccount = $this->findAccountByPurpose(
                $creditNote->company_id,
                SystemAccountPurpose::VatCollected
            );

            // 1. Create AR credit line - REVERSED from invoice, EX-STAMP only
            // (Q1, 2026-08-07 ruling): the credit note's own stamp duty no
            // longer reduces what the customer owes; it books as a separate
            // self-balancing pair below (3c).
            /** @var numeric-string $documentTotal */
            $documentTotal = (string) ($creditNote->total ?? '0');
            /** @var numeric-string $arCreditAmount */
            $arCreditAmount = bccomp($plan->stampDutyAmount, '0', $scale) > 0
                ? bcsub($documentTotal, $plan->stampDutyAmount, $scale)
                : $documentTotal;

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $arAccount->id,
                'partner_id' => $creditNote->partner_id,
                'debit' => '0',
                'credit' => $arCreditAmount,
                'description' => 'AR reversal from Credit Note '.$creditNote->document_number,
            ]);

            // 2. Create Revenue debit lines (one per credit note line) - REVERSED from invoice
            foreach ($creditNote->lines as $line) {
                // Determine revenue account based on product/service type
                $revenueAccount = $this->getRevenueAccountForLine(
                    $line,
                    $productRevenueAccount,
                    $serviceRevenueAccount
                );

                /** @var numeric-string $lineTotal */
                $lineTotal = $line->line_total ?? '0';
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $revenueAccount->id,
                    'debit' => $lineTotal,
                    'credit' => '0',
                    'description' => 'Revenue reversal from Credit Note '.$creditNote->document_number.' - Line '.$line->line_number,
                ]);
            }

            // 3. Create VAT debit lines (grouped by tax rate) - REVERSED from invoice
            foreach ($plan->taxByRate as $rate => $amount) {
                if (bccomp($amount, '0', $scale) > 0) {
                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $vatCollectedAccount->id,
                        'debit' => $amount,
                        'credit' => '0',
                        'description' => 'VAT reversal '.$rate.'% from Credit Note '.$creditNote->document_number,
                    ]);
                }
            }

            // 3b. Reverse the residual out: arFacingTotal − revenue − line VAT
            // rides in the AR credit and needs a debit leg, mirroring the
            // invoice posting. `residualPlan()` chose the account and
            // `DocumentPostingService` pre-flighted the verdict before the
            // credit note was sealed.
            //
            // Gate m-1 (2026-08-07): once `$plan->stampDutyAmount` is
            // positive, THIS leg is pure per-line tax-truncation dust — the
            // real stamp has its own explicit pair at 3c below — even when it
            // still lands on the SAME 4375 account a stampless CN's residual
            // would (`residualPlan()`'s absorbing-account ladder still prefers
            // `SalesStampDutyPayable` for the leftover after the stamp is
            // peeled out, unchanged, to avoid a TN regression). Label it
            // "stamp duty (timbre)" ONLY for a legacy stampless CN whose
            // residual happens to land on 4375 — the pre-existing, unchanged
            // shape (gate m-3's disposition-deferred case) — never when a
            // separate stamp pair is ALSO being written for this same entry.
            if ($plan->absorbingAccount !== null) {
                $isStampDuty = $plan->absorbingAccount->system_purpose === SystemAccountPurpose::SalesStampDutyPayable
                    && $plan->stampExpenseAccount === null;
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $plan->absorbingAccount->id,
                    'debit' => $plan->residual,
                    'credit' => '0',
                    'description' => ($isStampDuty ? 'Stamp duty (timbre) reversal' : 'Tax rounding difference reversal')
                        .' from Credit Note '.$creditNote->document_number,
                ]);
            }

            // 3c. Q1 (2026-08-07 ruling) — the credit note's OWN stamp duty,
            // booked as a separate self-balancing pair: DEBIT the fiscal-charge
            // expense account (the company bears this cost), CREDIT the
            // stamp-payable liability (the company owes the avoir's timbre to
            // the State). Never reduces AR — that is exactly what step 1 above
            // already stopped doing.
            //
            // Unreachable via the documented posting flow —
            // `assertDocumentGlIsPostable()` refuses pre-seal whenever the plan
            // could not resolve both accounts — but this is DELIBERATELY an
            // explicit throw, NOT an omit-and-let-`assertLegsBalance()`-catch-it
            // the way the residual leg above does: a self-balancing pair is,
            // by construction, invisible to the Σdebits==Σcredits check when
            // BOTH its legs are dropped together, so silently omitting it would
            // seal a balanced-but-WRONG entry that drops the company's real
            // fiscal charge and liability entirely.
            if (bccomp($plan->stampDutyAmount, '0', $scale) > 0) {
                if ($plan->stampExpenseAccount === null || $plan->stampPayableAccount === null) {
                    throw new \RuntimeException(sprintf(
                        'Credit note %s carries a stamp duty of %s but this chart of accounts has no stamp-charge '
                        .'and/or stamp-payable account — refusing to post an entry that would silently drop the '
                        ."company's fiscal charge. This should have been refused at pre-flight.",
                        $creditNote->document_number ?? $creditNote->id,
                        $plan->stampDutyAmount,
                    ));
                }

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $plan->stampExpenseAccount->id,
                    'debit' => $plan->stampDutyAmount,
                    'credit' => '0',
                    'description' => 'Stamp duty (timbre) on Credit Note '.$creditNote->document_number.' — fiscal charge',
                ]);
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $plan->stampPayableAccount->id,
                    'debit' => '0',
                    'credit' => $plan->stampDutyAmount,
                    'description' => 'Stamp duty (timbre) payable from Credit Note '.$creditNote->document_number,
                ]);
            }

            // 4. Calculate and set fiscal_hash AFTER lines are created
            $freshEntry = $entry->fresh(['lines']);
            if ($freshEntry === null) {
                throw new \RuntimeException('Failed to reload journal entry after creation');
            }

            // 4a. W-6 D1a (credit-note sibling) — defence in depth, same reason as
            // the invoice path: an unbalanced entry sealed into the immutable GL
            // hash chain is invisible to `verifyChain()` and cannot be edited out.
            $this->assertLegsBalance($freshEntry, 'credit note', $creditNote, $scale, $plan);

            $hash = $this->hashService->calculateHash($freshEntry, $previousHash);
            $entry->update(['fiscal_hash' => $hash]);

            // Dispatch JournalEntryCreated event for audit trail
            $entry = $entry->fresh(['lines']);
            if ($entry === null) {
                throw new \RuntimeException('Failed to reload journal entry after hash update');
            }

            $this->dispatchJournalEntryCreatedEvent($entry, 'credit_note', $scale);

            return $entry->id;
        });

        $this->refreshPartnerBalanceAfterGlPersistence(
            $creditNote->company_id,
            $creditNote->partner_id,
            $entryId
        );

        return $entryId;
    }

    private function refreshPartnerBalanceAfterGlPersistence(
        string $companyId,
        string $partnerId,
        string $journalEntryId,
    ): void {
        try {
            $this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);
        } catch (\Throwable $e) {
            Log::warning(
                'Could not refresh partner balance after GL entry creation: '.$e->getMessage(),
                [
                    'company_id' => $companyId,
                    'partner_id' => $partnerId,
                    'journal_entry_id' => $journalEntryId,
                    'exception' => $e::class,
                ]
            );
        }
    }

    /**
     * W-7 F-6 (c) — reverse a withdrawn document's GL.
     *
     * `DocumentPostingService::cancel()` made no GL call at all, so a cancelled
     * POSTED invoice left its AR debit, its revenue credits and its VAT credit
     * standing in the ledger forever.
     *
     * Three properties this method is built to guarantee:
     *
     * 1. **Mirror fidelity.** The legs are read back from the STORED entry and
     *    swapped, never recomputed from the document. Recomputation would run
     *    `residualPlan()` again against a chart, a tax table and an absorbing
     *    account that may all have moved since the seal — the reversal could then
     *    silently differ from what was actually posted. Reading the sealed legs
     *    makes divergence impossible by construction.
     * 2. **Balance by construction.** Σdr(mirror) == Σcr(original) and
     *    Σcr(mirror) == Σdr(original), so a balanced original yields a balanced
     *    mirror. Only an original that was ALREADY unbalanced can fail, and that
     *    is refused rather than sealed — see {@see UnreversibleDocumentGlException}.
     *    The assertion runs BEFORE anything is written.
     * 3. **Idempotence.** A document is reversed at most once, keyed on
     *    `source_type = DOCUMENT_CANCELLATION_SOURCE_TYPE` + `source_id`. Note
     *    that `journal_entries(source_type, source_id)` carries no uniqueness
     *    constraint, so this is an explicit check inside the caller's transaction,
     *    not a database guarantee.
     *
     * The original entry is never mutated or deleted: the ledger is immutable and
     * this is a forward correction, chained after it like any other entry.
     *
     * @throws UnreversibleDocumentGlException
     */
    public function reverseDocumentGl(Document $document): ?string
    {
        if (! in_array($document->type, [DocumentType::Invoice, DocumentType::CreditNote], true)) {
            return null;
        }

        $alreadyReversed = JournalEntry::query()
            ->where('company_id', $document->company_id)
            ->where('source_type', self::DOCUMENT_CANCELLATION_SOURCE_TYPE)
            ->where('source_id', $document->id)
            ->exists();

        if ($alreadyReversed) {
            return null;
        }

        /** @var Collection<int, JournalEntry> $originals */
        $originals = JournalEntry::query()
            ->where('company_id', $document->company_id)
            ->where('source_type', self::DOCUMENT_SOURCE_TYPE)
            ->where('source_id', $document->id)
            ->where('status', JournalEntryStatus::Posted)
            ->with('lines')
            ->orderBy('chain_sequence')
            ->get();

        if ($originals->isEmpty()) {
            // Never reached the ledger — a document posted before GL existed, a
            // type that posts none, or one whose posting was refused pre-seal by
            // the W-6 D1a pre-flight. Nothing to reverse; minting an empty entry
            // would be noise in the chain.
            return null;
        }

        $scale = $this->documentScale($document);
        $mirrorLegs = [];
        /** @var numeric-string $originalDebits */
        $originalDebits = '0';
        /** @var numeric-string $originalCredits */
        $originalCredits = '0';

        foreach ($originals as $original) {
            foreach ($original->lines as $line) {
                /** @var numeric-string $debit */
                $debit = (string) $line->debit;
                /** @var numeric-string $credit */
                $credit = (string) $line->credit;

                $originalDebits = bcadd($originalDebits, $debit, $scale);
                $originalCredits = bcadd($originalCredits, $credit, $scale);

                $mirrorLegs[] = [
                    'account_id' => $line->account_id,
                    'partner_id' => $line->partner_id,
                    // The mirror: every debit becomes a credit and back again.
                    'debit' => $credit,
                    'credit' => $debit,
                    'description' => 'Reversal of '.($line->description ?? $original->entry_number),
                ];
            }
        }

        // The SAME carve-out the posting paths use, for the same reason: a document
        // with NO lines posts a lone AR leg with no revenue side to balance against
        // on any chart without a `SalesStampDutyPayable` account. That is a
        // PRE-EXISTING broken shape which the L1 lane deliberately left
        // byte-identical rather than change under a merge gate
        // (`residualPlan()`, `DocumentGlResidualPlan::$balanceAssertable`,
        // docs/superpowers/tickets/2026-08-05-lineless-document-gl-posting.md).
        //
        // GL gate finding M-1: the mirror being "equal and opposite" does NOT
        // distinguish this case from the DOC06 refusal below — mirroring an
        // unbalanced original ALSO nets to zero. The real reason to skip the
        // assertion here is cancellability: refusing would make a lineless
        // document impossible to cancel at all, strictly worse than before this
        // lane (which reversed nothing and cancelled cleanly). This DOES seal a
        // new one-legged (unbalanced) entry into the chain — accepted as the
        // lesser evil, not claimed to be balanced.
        //
        // GL gate finding I-2: reuse `residualPlan()`'s OWN predicate rather than
        // a second, independent expression of it (`$document->lines->isEmpty()`
        // at `:147` vs. a hand-rolled `isNotEmpty()` here) — two expressions of
        // one fiscal invariant is exactly the drift class L1's own DTO comment
        // warns about. For any document with lines this is unconditionally
        // `true` (every non-lineless branch defaults `balanceAssertable` to
        // `true`), so behaviour is unchanged; only the lineless carve-out reuses
        // a single source now.
        $balanceAssertable = $this->residualPlan($document, $scale)->balanceAssertable;

        if ($balanceAssertable && bccomp($originalDebits, $originalCredits, $scale) !== 0) {
            throw UnreversibleDocumentGlException::forUnbalancedOriginal(
                $document->document_number ?? $document->id,
                (string) $originals->first()->entry_number,
                $originalDebits,
                $originalCredits,
            );
        }

        $entryId = DB::transaction(function () use ($document, $scale, $mirrorLegs, $balanceAssertable): string {
            $previousHash = JournalEntry::getLastChainHash($document->company_id);
            $chainSequence = JournalEntry::getNextChainSequence($document->company_id);

            $entry = JournalEntry::create([
                'tenant_id' => $document->tenant_id,
                'company_id' => $document->company_id,
                'entry_number' => 'REVCAN-'.now()->format('YmdHis').'-'.str_replace('-', '', $document->id),
                // The cancellation's OWN date, not the invoice's: the original
                // stands in its period and the reversal lands in the period the
                // withdrawal actually happened in.
                'entry_date' => now(),
                'description' => 'Cancellation of '.($document->document_number ?? $document->id),
                'status' => JournalEntryStatus::Posted,
                'source_type' => self::DOCUMENT_CANCELLATION_SOURCE_TYPE,
                'source_id' => $document->id,
                'chain_sequence' => $chainSequence,
                'previous_hash' => $previousHash,
            ]);

            foreach ($mirrorLegs as $leg) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $leg['account_id'],
                    'partner_id' => $leg['partner_id'],
                    'debit' => $leg['debit'],
                    'credit' => $leg['credit'],
                    'description' => $leg['description'],
                ]);
            }

            $freshEntry = $entry->fresh(['lines']);
            if ($freshEntry === null) {
                throw new \RuntimeException('Failed to reload reversal journal entry after creation');
            }

            // Defence in depth, same shape as the posting paths: the balance was
            // already proven above, so this can only fire if a leg was written
            // that the mirror did not describe.
            $this->assertLegsBalance(
                $freshEntry,
                'document_cancellation',
                $document,
                $scale,
                new DocumentGlResidualPlan('0', '0', '0', [], null, null, $balanceAssertable),
            );

            // GL gate finding I-5: pass the document's OWN currency rather than
            // falling to a no-arg `getScale()` resolve inside
            // `serializeForHashing()` — that resolves from the country row
            // (`CurrencyScaleResolver::scale()`), a DIFFERENT function from the
            // explicit-currency path `verifyChain()` always uses. They agree
            // today, but this is new code written after L1's "pass the
            // currency" doctrine, and the no-arg path also throws outside
            // request context (rule 20). `$document->currency` is already in
            // hand — no reason to take the fragile path.
            $entry->update(['fiscal_hash' => $this->hashService->calculateHash($freshEntry, $previousHash, $document->currency)]);

            $entry = $entry->fresh(['lines']);
            if ($entry === null) {
                throw new \RuntimeException('Failed to reload reversal journal entry after hash update');
            }

            $this->dispatchJournalEntryCreatedEvent($entry, 'document_cancellation', $scale);

            return $entry->id;
        });

        $this->refreshPartnerBalanceAfterGlPersistence(
            $document->company_id,
            $document->partner_id,
            $entryId
        );

        return $entryId;
    }

    /**
     * Find an account by SystemAccountPurpose.
     *
     * @throws \RuntimeException If account not found
     */
    private function findAccountByPurpose(
        string $companyId,
        SystemAccountPurpose $purpose
    ): Account {
        $account = Account::where('company_id', $companyId)
            ->where('system_purpose', $purpose)
            ->first();

        if ($account === null) {
            throw new \RuntimeException(
                "Account with purpose '{$purpose->value}' not found for company {$companyId}"
            );
        }

        return $account;
    }

    /**
     * Determine the revenue account for a document line based on product/service type.
     */
    private function getRevenueAccountForLine(
        DocumentLine $line,
        Account $productRevenueAccount,
        Account $serviceRevenueAccount
    ): Account {
        // If service_id is set, use service revenue account
        if ($line->service_id !== null) {
            return $serviceRevenueAccount;
        }

        // If product_id is set, check product type
        if ($line->product_id !== null && $line->product !== null) {
            // Service type products use service revenue account
            if ($line->product->type?->value === 'service') {
                return $serviceRevenueAccount;
            }
        }

        // Default to product revenue account
        return $productRevenueAccount;
    }

    /**
     * Group invoice lines by tax rate and calculate total tax for each rate.
     *
     * The per-line `bcmul` TRUNCATES at $scale, while the header's own tax was
     * accumulated at scale+1 and truncated once per rate bucket
     * (`TaxCalculationService`). Since `Σ trunc(xᵢ) <= trunc(Σ xᵢ)`, this
     * recomputation is biased DOWN relative to the header — which is why the
     * document residual runs positive and needs an absorbing account. See
     * {@see residualPlan()}.
     *
     * @param  Collection<int, DocumentLine>  $lines
     * @return array<numeric-string, numeric-string> Tax rate => Total tax amount
     */
    private function groupTaxByRate($lines, int $scale): array
    {
        $taxByRate = [];

        foreach ($lines as $line) {
            $taxRate = $line->tax_rate ?? '0';

            // Calculate tax amount for this line
            $taxAmount = bcmul(
                $line->line_total,
                bcdiv($taxRate, '100', 4),
                $scale
            );

            // Add to the rate's total
            if (! isset($taxByRate[$taxRate])) {
                $taxByRate[$taxRate] = '0';
            }

            $taxByRate[$taxRate] = bcadd($taxByRate[$taxRate], $taxAmount, $scale);
        }

        return $taxByRate;
    }

    /**
     * Dispatch JournalEntryCreated event for audit trail.
     *
     * @param  JournalEntry  $entry  The journal entry (with lines loaded)
     * @param  string  $entryType  The type of entry (invoice, credit_note, etc.)
     */
    private function dispatchJournalEntryCreatedEvent(JournalEntry $entry, string $entryType, int $scale): void
    {
        // Calculate total debits and credits from lines
        $totalDebit = '0';
        $totalCredit = '0';

        foreach ($entry->lines as $line) {
            $totalDebit = bcadd($totalDebit, $line->debit, $scale);
            $totalCredit = bcadd($totalCredit, $line->credit, $scale);
        }

        event(new JournalEntryCreated(
            journalEntryId: $entry->id,
            tenantId: $entry->tenant_id,
            companyId: $entry->company_id,
            entryNumber: $entry->entry_number,
            entryDate: $entry->entry_date->format('Y-m-d'),
            entryType: $entryType,
            sourceType: $entry->source_type ?? '',
            sourceId: $entry->source_id ?? '',
            totalDebit: $totalDebit,
            totalCredit: $totalCredit,
            fiscalHash: $entry->fiscal_hash ?? '',
            chainSequence: $entry->chain_sequence ?? 0,
            createdAt: now()->toIso8601String(),
        ));
    }

    private function sourceEntryNumber(string $prefix, Document $document): string
    {
        $documentId = str_replace('-', '', $document->id);

        return $prefix.'-'.$document->document_date->format('YmdHis').'-'.$documentId;
    }
}
