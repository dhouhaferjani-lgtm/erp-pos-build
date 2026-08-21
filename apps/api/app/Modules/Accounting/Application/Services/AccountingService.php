<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Application\DTOs\DocumentGlResidualPlan;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\GlResidualRefusal;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\JournalEntryCreated;
use App\Modules\Accounting\Domain\Exceptions\ClosedFiscalPeriodException;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Domain\Exceptions\UnpostableCorrectingEntryException;
use App\Modules\Accounting\Domain\Exceptions\UnpostableDocumentGlException;
use App\Modules\Accounting\Domain\Exceptions\UnreversibleDocumentGlException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\DoubleEntryValidator;
use App\Modules\Document\Application\DTOs\CorrectingEntryLegData;
use App\Modules\Document\Application\DTOs\CorrectingEntryPayload;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\Accounting\DocumentGlCorrectionInterface;
use App\Shared\Contracts\Accounting\DocumentGlPreflightInterface;
use App\Shared\Contracts\Accounting\DocumentGlReversalInterface;
use App\Shared\Contracts\AccountingServiceInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Taxation\DocumentPeriodLockInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Application service for accounting operations.
 *
 * Exposes accounting functionality to other modules through the AccountingServiceInterface.
 */
final class AccountingService implements AccountingServiceInterface, DocumentGlCorrectionInterface, DocumentGlPreflightInterface, DocumentGlReversalInterface
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

    /**
     * `journal_entries.source_type` for the entry a CORRECTING-ENTRY document
     * posts (R2-F4, owner ruling c4).
     *
     * A third distinct value, on the same reasoning as
     * {@see self::DOCUMENT_CANCELLATION_SOURCE_TYPE}: a correction must never be
     * mistaken for the document posting it repairs, nor for a reversal.
     *
     * KEYING — `source_id` is the CORRECTING document's own id, NOT the target's.
     * That differs from the cancellation idiom (which keys the original) for one
     * concrete reason: a document may accumulate SEVERAL corrections over time,
     * so keying on the target could not express "has THIS correction been
     * posted?" and idempotence would be unimplementable. The link to the
     * original is not lost — it lives on `documents.source_document_id`, which is
     * exactly where owner ruling c4 requires it, and
     * {@see self::correctingEntryDocumentIdsFor()} is the one query that resolves it.
     *
     * @var string
     */
    public const DOCUMENT_CORRECTION_SOURCE_TYPE = 'DocumentCorrection';

    /**
     * The document types a correcting entry may target.
     *
     * Deliberately IDENTICAL to `reverseDocumentGl()`'s own type gate, and it
     * must stay that way: the whole point of the correction is to make a refused
     * cancellation possible again, which only works if both sides agree on which
     * documents they are talking about. Every other document family keys its GL
     * through `GeneralLedgerService` under snake_case source types
     * (`supplier_invoice`, `expense`, `income`, …) that the aggregate below does
     * not read, so accepting them would silently apply a weaker invariant.
     *
     * @var list<DocumentType>
     */
    private const CORRECTABLE_TARGET_TYPES = [
        DocumentType::Invoice,
        DocumentType::CreditNote,
    ];

    /**
     * The account purposes whose balance IS a partner subledger.
     *
     * Exactly the set `CheckSubledgerReconciliationCommand` reconciles by default
     * (`:118-120`), and that is the point: an account this command checks is an
     * account whose control balance must equal Σ(partner statements). A leg
     * posted to one of these without a `partner_id` moves the control side and
     * nothing else, and the divergence is permanent — the journal is immutable,
     * so there is no later edit that can attach the partner.
     *
     * @var list<SystemAccountPurpose>
     */
    private const PARTNER_CONTROL_PURPOSES = [
        SystemAccountPurpose::CustomerReceivable,
        SystemAccountPurpose::CustomerAdvance,
        SystemAccountPurpose::SupplierPayable,
    ];

    /**
     * The control purposes whose partner may be INHERITED from the target.
     *
     * A correcting entry's target is always a CUSTOMER document — Invoice or
     * CreditNote, per {@see self::CORRECTABLE_TARGET_TYPES} — so its
     * `partner_id` is a customer. Inheriting it onto a CustomerReceivable or
     * CustomerAdvance leg is sound: the correction is about that customer's
     * balance by construction.
     *
     * `SupplierPayable` is DELIBERATELY ABSENT. Inheriting there would stamp a
     * CUSTOMER id into the supplier subledger, which reconciles as cleanly as it
     * is wrong: `reconcileSubledger(SupplierPayable)` would balance, on a partner
     * that never owed the money. A 401 leg therefore requires an EXPLICIT
     * partner, and is refused without one (treasury gate P3-1).
     *
     * @var list<SystemAccountPurpose>
     */
    private const TARGET_PARTNER_INHERITABLE_PURPOSES = [
        SystemAccountPurpose::CustomerReceivable,
        SystemAccountPurpose::CustomerAdvance,
    ];

    /**
     * The account purposes that feed the VAT DECLARATION.
     *
     * A leg touching one of these inside a FILED period moves the ledger away
     * from a return that is already lodged with the tax authority.
     *
     * @var list<SystemAccountPurpose>
     */
    private const VAT_CONTROL_PURPOSES = [
        SystemAccountPurpose::VatCollected,
        SystemAccountPurpose::VatDeductible,
    ];

    /**
     * The `DocumentPeriodLockInterface::cancellationRefusalCode()` value that
     * means "the covering VAT period is FILED".
     *
     * Compared as a STRING on purpose. The Shared contract deliberately returns
     * the backing values of Taxation's `PeriodLockRefusalCode` rather than the
     * enum itself, precisely so Accounting does not have to depend on a Taxation
     * Domain type to read the answer (rule 6). Importing the enum to avoid this
     * literal would defeat the contract's whole design.
     */
    private const PERIOD_FILED_REFUSAL_CODE = 'DOCUMENT_PERIOD_FILED';

    public function __construct(
        private readonly GeneralLedgerHashService $hashService,
        private readonly PartnerBalanceService $partnerBalanceService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly DoubleEntryValidator $doubleEntryValidator,
        private readonly FiscalPeriodResolverService $fiscalPeriodResolver,
        private readonly DocumentPeriodLockInterface $vatPeriodLock,
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
     * That "never a 422" is enforced by the PARENT CLASS of
     * `UnbalancedJournalEntryException`, and the parent is load-bearing:
     * re-parenting it under `\InvalidArgumentException` (a `\LogicException`)
     * exposes this refusal to the `catch (\InvalidArgumentException)` blocks in `app/`
     * that render 400/422 — **20** real clauses in that literal form (23 raw grep
     * matches minus 3 that are comment/docblock prose) and **50** real clauses
     * matching `catch (…InvalidArgumentException…)` (54 raw minus 4 comments) —
     * and drops it out of the
     * `catch (\RuntimeException)` in `CreditNoteController::post()` that maps it to a
     * detailed 500.
     * enforcement-P3 M1 did exactly that and reverted it (round 1, findings 3/4);
     * `ChokepointUnbalancedGuardTest::test_the_two_unbalanced_types_keep_their_load_bearing_parents`
     * now guards it.
     *
     * **This method is the ONLY source of `UnbalancedJournalEntryException`.** The GL
     * posting chokepoint (`GeneralLedgerService::sealAndPersistEntry`) raises a
     * DIFFERENT type — `UnbalancedJournalEntryPostException`
     * (`App\Modules\Accounting\Domain\Exceptions`), a sibling under
     * `\InvalidArgumentException`, not under `\RuntimeException`.
     * They are deliberately unrelated: the two refusals need opposite catch
     * semantics, and one shared parent broke a live contract in whichever direction
     * it was chosen. **A `catch` written to intercept the CHOKEPOINT's refusal must
     * target the `…PostException` / `\InvalidArgumentException` family — catching
     * `\RuntimeException` will not match it.**
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
     *    The assertion runs BEFORE anything is written. R2-F4: "the original" here
     *    means the document's whole ledger footprint — its own entry PLUS every
     *    correcting-entry document linked to it — so a correction that rebalances
     *    it lifts the refusal. {@see self::documentLedgerFootprint()}.
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

        // R2-F4 — the document's WHOLE ledger footprint, not just the entry its
        // own posting sealed: every correcting-entry document linked to it
        // (`documents.source_document_id`) contributes its legs here too.
        //
        // Two consequences, both intended:
        //   (a) THE ESCAPE HATCH. The balance count below now SEES a correction,
        //       so an original that was sealed out of balance can be repaired by
        //       a correcting document and then cancelled. Before this, the count
        //       matched only `source_type = 'Document' AND source_id = $document->id`
        //       while the only manual-entry writer hard-codes `'manual'`, so no
        //       correction reachable through the product could ever enter the
        //       predicate and the refusal was a permanent dead end
        //       (`docs/superpowers/tickets/2026-08-06-l2-correcting-entry-escape-hatch.md`).
        //   (b) The mirror REVERSES the corrections too. Withdrawing the document
        //       must withdraw everything posted about it; leaving a correction's
        //       legs standing would strand a correction of a document that no
        //       longer exists.
        //
        // For an uncorrected document this is byte-identical to the previous
        // query — the OR branch is not even emitted when there are no linked
        // corrections.
        $originals = $this->documentLedgerFootprint($document);

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

    // =====================================================================
    // R2-F4 — correcting-entry documents (owner ruling c4)
    // =====================================================================

    /**
     * {@inheritDoc}
     */
    public function assertCorrectingEntryIsWellFormed(Document $correctingEntry): void
    {
        $this->resolveCorrectingEntry($correctingEntry, assertBalance: false);
    }

    /**
     * {@inheritDoc}
     */
    public function assertCorrectingEntryIsPostable(Document $correctingEntry): void
    {
        $this->resolveCorrectingEntry($correctingEntry, assertBalance: true);
    }

    /**
     * Post a correcting-entry document to the general ledger.
     *
     * DATING — `now()`, never the target's `document_date`. Same doctrine as
     * `reverseDocumentGl()`: the original stands in its own period and the
     * correction lands in the period the correction actually happened in.
     *
     * PERIOD-LOCK DECISION (R2-F4, recorded here because this is where it bites):
     * a correcting entry MAY target an original whose VAT period is CLOSED or
     * FILED. That is the entire purpose of the escape hatch — the document
     * needing repair is by definition one whose books were closed with a defect
     * in them, and refusing would make the defect permanent. Nothing inside the
     * locked period is rewritten. F1's `VatPeriodCancellationGuard` is therefore
     * deliberately NOT consulted here: it guards WITHDRAWAL of a document from a
     * locked period, not forward correction into the current one. Pinned by
     * `CorrectingEntryGlPostingTest::test_a_correcting_entry_may_target_an_original_in_a_filed_period()`.
     *
     * RE-VERIFIED 2026-08-21 against the period-lock surface `dev` has grown
     * since this lane branched (the CF cancel-flow lane). The claim above still
     * holds, on both contracts, for reasons that are structural rather than
     * incidental:
     *
     *  - `DocumentPeriodLockInterface::assertCancellationPeriodIsOpen()`
     *    (`app/Shared/Contracts/Taxation/DocumentPeriodLockInterface.php`) asks
     *    whether a document may be WITHDRAWN, and resolves the period from the
     *    document's OWN `document_date`
     *    (`VatPeriodCancellationGuard::lockedPeriodFor()`). A correction
     *    withdraws nothing and is dated `now()`, so the question does not apply.
     *    Its population also excludes this type outright:
     *    `VatPeriodCancellationGuard::refusalAppliesTo()` admits only
     *    SALES_LEDGER_BEARING_TYPES (Invoice, CreditNote, Income) and
     *    PURCHASE_DOCUMENT_TYPES (SupplierInvoice, SupplierCreditNote, Expense),
     *    so calling it with a CorrectingEntry would be a guaranteed no-op —
     *    consulting it would be theatre, not protection.
     *  - `PeriodBackdatingGuardInterface::assertBackdatingPeriodIsOpen()`
     *    (`app/Shared/Contracts/Taxation/PeriodBackdatingGuardInterface.php`)
     *    asks whether a document may be DATED INTO a period, keyed on the date
     *    the user typed. A correcting entry offers the user no date at all: both
     *    `documents.document_date` and this entry's `entry_date` are `now()`. It
     *    is therefore structurally incapable of backdating, which is exactly why
     *    the guard is not wired here.
     *
     * KNOWN RESIDUAL, inherited and deliberately not closed in this lane: if the
     * CURRENT period is itself already CLOSED or FILED, this entry still posts
     * into it. That is the same gap `reverseDocumentGl()` carries — neither path
     * is routed through `GeneralLedgerService::postEntryNow()`, so neither
     * consults `fiscal_periods` (ticket
     * `2026-08-07-cancel-reversal-bypasses-closed-fiscal-period.md`). Closing it
     * for one path and not the other would be worse than either: a correction
     * that is refused where the cancellation it exists to unblock is permitted
     * re-creates the dead end this whole lane was built to remove. Both close
     * together or neither does.
     *
     * {@inheritDoc}
     */
    public function postCorrectingEntryGl(Document $correctingEntry): string
    {
        // Every partner whose subledger this entry moves, collected inside the
        // transaction and refreshed after it commits.
        /** @var list<string> $touchedPartnerIds */
        $touchedPartnerIds = [];

        $entryId = DB::transaction(function () use ($correctingEntry, &$touchedPartnerIds): string {
            // STEP 1, before ANY read — the same per-company transaction-scoped
            // advisory lock the GL chokepoint takes
            // (`GeneralLedgerService::sealAndPersistEntry()`), with the same
            // idiom and the same argument. This path allocates `chain_sequence`
            // and reads `previous_hash` with unlocked `max()` queries; without
            // the lock it can interleave with a concurrent legitimate post and
            // allocate a duplicate sequence — an FEC-sequentiality break. Taking
            // it FIRST also serialises the already-posted probe below with the
            // insert that would satisfy it, closing the double-post TOCTOU in
            // the same stroke (treasury gate P1-3 / P2-1).
            //
            // Released at COMMIT, never earlier, and a no-op outside pgsql.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$correctingEntry->company_id]);
            }

            // Re-resolved INSIDE the transaction: the aggregate-balance verdict
            // depends on rows another request may be writing concurrently, so a
            // pre-flight answer computed outside would be stale by construction.
            $resolved = $this->resolveCorrectingEntry($correctingEntry, assertBalance: true);
            $target = $resolved['target'];
            $legs = $resolved['legs'];
            $scale = $resolved['scale'];

            $entryDate = now();

            // Ordinary period control, on the SAME terms the chokepoint applies
            // it: a `fiscal_periods` row that EXISTS and is Closed/Locked for the
            // entry date refuses; absence permits, so no company that has not
            // configured periods is bricked. Checked AFTER the advisory lock so
            // the verdict shares the serialised view the sequence allocation uses.
            //
            // This is not the target's period — a correction lands in the period
            // it is made in — so it is not the "may I correct a closed period?"
            // question the docblock above answers. It is "may I write to the
            // ledger TODAY?", which for arbitrary admin-authored legs has exactly
            // one right answer and it is the chokepoint's.
            if ($this->fiscalPeriodResolver->isDateInClosedPeriod($correctingEntry->company_id, $entryDate)) {
                throw new ClosedFiscalPeriodException(
                    $correctingEntry->company_id,
                    $entryDate->toDateString(),
                );
            }

            $previousHash = JournalEntry::getLastChainHash($correctingEntry->company_id);
            $chainSequence = JournalEntry::getNextChainSequence($correctingEntry->company_id);

            $entry = JournalEntry::create([
                'tenant_id' => $correctingEntry->tenant_id,
                'company_id' => $correctingEntry->company_id,
                'entry_number' => 'CORR-'.$entryDate->format('YmdHis').'-'.str_replace('-', '', $correctingEntry->id),
                'entry_date' => $entryDate,
                'description' => 'Correction of '.($target->document_number ?? $target->id)
                    .' — '.$resolved['reason'],
                'status' => JournalEntryStatus::Posted,
                'source_type' => self::DOCUMENT_CORRECTION_SOURCE_TYPE,
                'source_id' => $correctingEntry->id,
                'chain_sequence' => $chainSequence,
                'previous_hash' => $previousHash,
            ]);

            foreach ($legs as $leg) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $leg->accountId,
                    // Resolved by `resolveCorrectingEntry()`, which REFUSED the
                    // entry rather than reach here with a control-account leg
                    // carrying no partner. Every sibling path stamps this column;
                    // omitting it is what broke subledger reconciliation.
                    'partner_id' => $leg->partnerId,
                    'debit' => $leg->debit,
                    'credit' => $leg->credit,
                    'description' => $leg->description
                        ?? 'Correction of '.($target->document_number ?? $target->id),
                ]);

                if ($leg->partnerId !== null) {
                    $touchedPartnerIds[] = $leg->partnerId;
                }
            }

            $freshEntry = $entry->fresh(['lines']);
            if ($freshEntry === null) {
                throw new \RuntimeException('Failed to reload correcting journal entry after creation');
            }

            // NOTE — deliberately NOT `assertLegsBalance()`. That helper asserts a
            // single entry balances on its own, which a correcting entry
            // legitimately does not: the canonical case (W-6 D1b) is supplying
            // the ONE leg a broken original is missing. The balance promise this
            // entry makes is the AGGREGATE one, already proven by
            // `resolveCorrectingEntry()` above from rows read inside this same
            // transaction — a stronger statement than the per-entry check,
            // because it covers the original and every prior correction too.

            $entry->update([
                'fiscal_hash' => $this->hashService->calculateHash(
                    $freshEntry,
                    $previousHash,
                    $correctingEntry->currency,
                ),
            ]);

            $entry = $entry->fresh(['lines']);
            if ($entry === null) {
                throw new \RuntimeException('Failed to reload correcting journal entry after hash update');
            }

            $this->dispatchJournalEntryCreatedEvent($entry, 'document_correction', $scale);

            return $entry->id;
        });

        // EVERY partner the legs actually touched, not just the correcting
        // document's own — a leg may name a different partner than the target's,
        // and the cached balance of a partner whose control account moved is
        // stale until it is recomputed. Before the legs carried `partner_id` at
        // all this call was decorative; now it has something to recompute from.
        foreach (array_unique($touchedPartnerIds) as $partnerId) {
            $this->refreshPartnerBalanceAfterGlPersistence(
                $correctingEntry->company_id,
                $partnerId,
                $entryId
            );
        }

        return $entryId;
    }

    /**
     * Every posted correcting-entry DOCUMENT that targets `$target`.
     *
     * The resolution of ruling c4's mandated link: `documents.source_document_id`
     * is what ties a correction to the document it repairs, and this is the only
     * place that walks it. Scoped by company as well as by link, because
     * `source_document_id` carries no FK and no scoping of its own
     * (`2025_11_30_080000_create_documents_table.php:33`).
     *
     * DELIBERATELY UNFILTERED BY DOCUMENT STATUS, and `withTrashed()`. The
     * question this list feeds is "what is in the LEDGER", and the ledger is
     * immutable: once a correcting entry is sealed, its legs are part of the
     * target's balance whatever later happens to the document row that produced
     * it. Filtering on `documents.status` (or letting the soft-delete scope run)
     * would silently drop sealed legs from the aggregate and hand both the
     * balance invariant and `reverseDocumentGl()` a false picture — the exact
     * class of divergence that made the escape-hatch dead end possible. The
     * posted-ness that DOES matter is asserted on the journal entry itself in
     * {@see self::documentLedgerFootprint()}.
     *
     * @return list<string> The correcting documents' ids.
     */
    private function correctingEntryDocumentIdsFor(Document $target): array
    {
        /** @var list<string> */
        return Document::query()
            ->withTrashed()
            ->where('company_id', $target->company_id)
            ->where('type', DocumentType::CorrectingEntry)
            ->where('source_document_id', $target->id)
            ->pluck('id')
            ->all();
    }

    /**
     * A document's WHOLE posted ledger footprint: the entry its own posting
     * sealed, plus every correcting entry applied to it.
     *
     * This is the single query both `reverseDocumentGl()` and the correcting
     * entry's own balance invariant read, so the two can never disagree about
     * what "this document's ledger" means — the exact disagreement that made the
     * escape-hatch ticket's dead end possible
     * (`2026-08-06-l2-correcting-entry-escape-hatch.md`: a correction posted
     * through the manual endpoint could never enter the reversal's predicate).
     *
     * @return Collection<int, JournalEntry>
     */
    private function documentLedgerFootprint(Document $target): Collection
    {
        $correctionIds = $this->correctingEntryDocumentIdsFor($target);

        /** @var Collection<int, JournalEntry> */
        return JournalEntry::query()
            ->where('company_id', $target->company_id)
            ->where('status', JournalEntryStatus::Posted)
            ->where(function ($query) use ($target, $correctionIds): void {
                $query->where(function ($own) use ($target): void {
                    $own->where('source_type', self::DOCUMENT_SOURCE_TYPE)
                        ->where('source_id', $target->id);
                });

                if ($correctionIds !== []) {
                    $query->orWhere(function ($corrections) use ($correctionIds): void {
                        $corrections->where('source_type', self::DOCUMENT_CORRECTION_SOURCE_TYPE)
                            ->whereIn('source_id', $correctionIds);
                    });
                }
            })
            ->with('lines')
            ->orderBy('chain_sequence')
            ->get();
    }

    /**
     * Validate a correcting-entry document end to end and return everything the
     * write needs.
     *
     * Runs every refusal in one place so the pre-flight and the write can never
     * reach different verdicts (the `assertDocumentGlIsPostable()` /
     * `createInvoiceGLEntries()` split learned this the hard way — GL gate I-2).
     *
     * @param  bool  $assertBalance  FALSE for the structural (draft-time) verdict —
     *                               see the contract's docblock for why the
     *                               balance check is not a creation-time refusal.
     * @return array{target: Document, legs: list<CorrectingEntryLegData>, scale: int, reason: string}
     *
     * @throws UnpostableCorrectingEntryException
     */
    private function resolveCorrectingEntry(Document $correctingEntry, bool $assertBalance): array
    {
        if ($correctingEntry->type !== DocumentType::CorrectingEntry) {
            // A programming error, not a business refusal: no caller should ever
            // hand a non-correcting document to this path.
            throw new \InvalidArgumentException(sprintf(
                'Expected a %s document, got %s.',
                DocumentType::CorrectingEntry->value,
                $correctingEntry->type->value,
            ));
        }

        $targetId = $correctingEntry->source_document_id;
        if ($targetId === null || $targetId === '') {
            throw UnpostableCorrectingEntryException::missingSourceDocument($correctingEntry->document_number);
        }

        if ($assertBalance) {
            $alreadyPosted = JournalEntry::query()
                ->where('company_id', $correctingEntry->company_id)
                ->where('source_type', self::DOCUMENT_CORRECTION_SOURCE_TYPE)
                ->where('source_id', $correctingEntry->id)
                ->exists();

            if ($alreadyPosted) {
                throw UnpostableCorrectingEntryException::alreadyPosted($correctingEntry->document_number);
            }
        }

        /** @var Document|null $target */
        $target = Document::query()
            ->where('tenant_id', $correctingEntry->tenant_id)
            ->where('company_id', $correctingEntry->company_id)
            ->whereKey($targetId)
            ->with('lines')
            ->first();

        if ($target === null) {
            throw UnpostableCorrectingEntryException::targetNotFound(
                $correctingEntry->document_number,
                $targetId,
            );
        }

        if (! in_array($target->type, self::CORRECTABLE_TARGET_TYPES, true)) {
            throw UnpostableCorrectingEntryException::unsupportedTargetType(
                $correctingEntry->document_number,
                $target->type->value,
            );
        }

        // A WITHDRAWN target cannot be corrected — and this is unconditional, not
        // part of the balance verdict, because withdrawal is irreversible: once
        // true it never becomes false again, which is exactly the structural
        // verdict's criterion.
        //
        // Both halves matter. `status = Cancelled` is the document's own answer;
        // the reversal-entry probe is the LEDGER's, and they can disagree —
        // `reverseDocumentGl()` is idempotent and keyed on its own source type,
        // so a document whose GL was already unwound must be refused even if some
        // path left the status behind. Posting into that gap is what the gate
        // probe caught: a 50.000 reclass landing on a cancelled invoice's AR and
        // VAT, with the mirror already written and nothing left to unwind it.
        $alreadyReversed = JournalEntry::query()
            ->where('company_id', $target->company_id)
            ->where('source_type', self::DOCUMENT_CANCELLATION_SOURCE_TYPE)
            ->where('source_id', $target->id)
            ->exists();

        if ($target->status === DocumentStatus::Cancelled || $alreadyReversed) {
            throw UnpostableCorrectingEntryException::targetAlreadyWithdrawn(
                $correctingEntry->document_number,
                $target->document_number,
            );
        }

        try {
            $payload = CorrectingEntryPayload::fromDocumentPayload($correctingEntry->payload);
        } catch (\InvalidArgumentException $exception) {
            throw UnpostableCorrectingEntryException::malformedPayload(
                $correctingEntry->document_number,
                $exception->getMessage(),
            );
        }

        // The chart of accounts is Accounting's own; Document never reaches into
        // it, so this is where a leg's account is proven to exist AND to belong
        // to this tenant and company (W-8 F-1: company_id is an authorization
        // axis, not a filter).
        $accountIds = array_values(array_unique(array_map(
            static fn (CorrectingEntryLegData $leg): string => $leg->accountId,
            $payload->legs,
        )));

        /** @var Collection<int, Account> $accounts */
        $accounts = Account::query()
            ->where('tenant_id', $correctingEntry->tenant_id)
            ->where('company_id', $correctingEntry->company_id)
            ->whereIn('id', $accountIds)
            ->get();

        /** @var array<string, Account> $accountsById */
        $accountsById = $accounts->keyBy('id')->all();

        foreach ($accountIds as $accountId) {
            if (! array_key_exists($accountId, $accountsById)) {
                throw UnpostableCorrectingEntryException::unknownAccount(
                    $correctingEntry->document_number,
                    $accountId,
                );
            }
        }

        // The PARTNER axis, proven exactly as the ACCOUNT axis is proven three
        // statements above — and for the same reason, which the account block
        // already states: company_id is an AUTHORIZATION axis, not a filter.
        //
        // Until this existed, `legs.*.partner_id` was validated for FORMAT only
        // (`uuid` in the FormRequest) and no query ever confirmed the partner was
        // real, let alone this company's. Two probes went through:
        //
        //  - a SIBLING-COMPANY partner was accepted onto a 411 leg. Nothing
        //    downstream catches it: `getSubledgerTotal()` never scopes partners
        //    by company, so the reconciler cannot see the foreign row, and the
        //    balance refresh dies inside its own try/catch as a swallowed
        //    ModelNotFoundException. The divergence is silent and permanent.
        //  - a well-formed but NONEXISTENT uuid reached the INSERT and became an
        //    FK violation — an untyped 500 where a typed 422 belongs.
        //
        // Only SUPPLIED leg partners are resolved here. A partner INHERITED from
        // the target needs no separate proof: it is read off a document this
        // method has already scoped to the same tenant and company.
        $suppliedPartnerIds = array_values(array_unique(array_filter(array_map(
            static fn (CorrectingEntryLegData $leg): ?string => $leg->partnerId,
            $payload->legs,
        ))));

        if ($suppliedPartnerIds !== []) {
            $knownPartnerIds = Partner::query()
                ->where('tenant_id', $correctingEntry->tenant_id)
                ->where('company_id', $correctingEntry->company_id)
                ->whereIn('id', $suppliedPartnerIds)
                ->pluck('id')
                ->all();

            foreach ($suppliedPartnerIds as $partnerId) {
                if (! in_array($partnerId, $knownPartnerIds, true)) {
                    throw UnpostableCorrectingEntryException::unknownPartner(
                        $correctingEntry->document_number,
                        $partnerId,
                    );
                }
            }
        }

        $scale = $this->documentScale($correctingEntry);

        // Is the target's VAT period FILED? Asked ONCE, before the leg loop, and
        // only when a VAT leg is actually present — the answer costs a repository
        // query and most corrections touch no VAT account at all.
        $targetPeriodIsFiled = null;

        // RESTATE every leg before anything reads its amounts.
        //
        // Two facts only Accounting holds, applied here so no later reader has to
        // remember them:
        //
        //  1. SCALE. The FormRequest's ceiling is the COLUMN scale (3 decimals);
        //     the ledger's arithmetic runs at the CURRENCY scale, which for EUR
        //     is 2 — and `bcadd` TRUNCATES rather than rounds. A leg of `0.005`
        //     on a EUR company therefore contributed `0.00` to the balance
        //     verdict and `0.005` to the column, which is how the fiscal gate's
        //     probe posted a "balanced" entry that left the company ledger
        //     permanently out by 0.004. Over-precision is REFUSED, never
        //     silently truncated: on a correction, an amount that is not the
        //     amount the accountant stated is a new defect, not a repair.
        //
        //  2. PARTNER. A leg on a partner control account must carry
        //     `journal_lines.partner_id`, exactly as every sibling GL path does
        //     (`createInvoiceGLEntries` :501, the reversal mirror :929). Without
        //     it the control account moves and no partner statement moves with
        //     it, and `reconcileSubledger()` reports the divergence forever.
        //     The leg's own `partner_id` wins; absent that, the TARGET's partner
        //     is the answer FOR CUSTOMER control accounts only, because a
        //     correction to an invoice's AR is by construction about that
        //     invoice's customer. A SupplierPayable leg inherits nothing — see
        //     TARGET_PARTNER_INHERITABLE_PURPOSES. Neither available on a control
        //     leg is a refusal, never a guess.
        $restatedLegs = [];

        foreach ($payload->legs as $leg) {
            $account = $accountsById[$leg->accountId];

            $debit = $this->restateLegAmount(
                $correctingEntry,
                $leg->accountId,
                $leg->debit,
                $scale,
            );
            $credit = $this->restateLegAmount(
                $correctingEntry,
                $leg->accountId,
                $leg->credit,
                $scale,
            );

            $partnerId = $leg->partnerId;

            if (in_array($account->system_purpose, self::PARTNER_CONTROL_PURPOSES, true)) {
                $mayInherit = in_array(
                    $account->system_purpose,
                    self::TARGET_PARTNER_INHERITABLE_PURPOSES,
                    true,
                );

                if ($partnerId === null && $mayInherit) {
                    $partnerId = $this->usablePartnerId($target);
                }

                if ($partnerId === null) {
                    throw UnpostableCorrectingEntryException::controlAccountLegWithoutPartner(
                        $correctingEntry->document_number,
                        $account->code,
                        $mayInherit,
                    );
                }
            }

            if (in_array($account->system_purpose, self::VAT_CONTROL_PURPOSES, true)) {
                $targetPeriodIsFiled ??= $this->vatPeriodLock->cancellationRefusalCode($target)
                    === self::PERIOD_FILED_REFUSAL_CODE;

                if ($targetPeriodIsFiled) {
                    throw UnpostableCorrectingEntryException::vatLegInFiledPeriod(
                        $correctingEntry->document_number,
                        $target->document_number,
                        $account->code,
                    );
                }
            }

            $restatedLegs[] = $leg->restated($debit, $credit, $partnerId);
        }

        // Everything below depends on the target's CURRENT ledger, which is
        // exactly what the structural verdict must not judge: a document's own GL
        // is written by a post-commit listener, so "no ledger entry yet" is a
        // transient state, and the balance can move between drafting and posting.
        if ($assertBalance) {
            $footprint = $this->documentLedgerFootprint($target);

            if ($footprint->isEmpty()) {
                throw UnpostableCorrectingEntryException::targetHasNoLedgerEntry(
                    $correctingEntry->document_number,
                    $target->document_number,
                );
            }

            /** @var numeric-string $debits */
            $debits = '0';
            /** @var numeric-string $credits */
            $credits = '0';

            foreach ($footprint as $entry) {
                foreach ($entry->lines as $line) {
                    /** @var numeric-string $lineDebit */
                    $lineDebit = (string) $line->debit;
                    /** @var numeric-string $lineCredit */
                    $lineCredit = (string) $line->credit;
                    $debits = bcadd($debits, $lineDebit, $scale);
                    $credits = bcadd($credits, $lineCredit, $scale);
                }
            }

            // The RESTATED legs, not the raw payload: the verdict must be reached
            // on the same numbers that reach `journal_lines`, or it certifies a
            // balance the ledger does not have (fiscal gate P1-3).
            foreach ($restatedLegs as $leg) {
                $debits = bcadd($debits, $leg->debit, $scale);
                $credits = bcadd($credits, $leg->credit, $scale);
            }

            // THE INVARIANT. Not "does this entry balance on its own" — a
            // correcting entry legitimately does not, when it supplies the one leg
            // a broken original is missing — but "does the corrected document
            // balance now".
            if (bccomp($debits, $credits, $scale) !== 0) {
                throw UnpostableCorrectingEntryException::leavesTargetUnbalanced(
                    $correctingEntry->document_number,
                    $target->document_number,
                    $debits,
                    $credits,
                );
            }
        }

        return [
            'target' => $target,
            'legs' => $restatedLegs,
            'scale' => $scale,
            'reason' => $payload->reason,
        ];
    }

    /**
     * A document's partner id, or NULL when it genuinely has none.
     *
     * Reads through `getAttribute()` DELIBERATELY, rather than `$target->partner_id`.
     * The model's docblock still declares `@property string $partner_id`
     * (`Document.php:44`), but the column has been NULLABLE since
     * `2026_06_27_110000_make_documents_partner_id_nullable.php` — the annotation
     * is stale, and PHPStan believes it, which is how `$partnerId === null` reads
     * as "always false" on a value that can very much be null at runtime.
     *
     * Going through the attribute bag returns `mixed`, so `is_string()` PROVES
     * what the docblock only asserts. This is a local defence, not a fix: the
     * stale annotation is repo-wide (every `refreshPartnerBalanceAfterGlPersistence()`
     * call site passes it into a `string` parameter) and correcting it belongs to
     * its own lane.
     */
    private function usablePartnerId(Document $target): ?string
    {
        $partnerId = $target->getAttribute('partner_id');

        return is_string($partnerId) && $partnerId !== '' ? $partnerId : null;
    }

    /**
     * One leg amount, restated at the currency scale — or refused.
     *
     * `bcformatStrict()` truncates, so a value that survives it unchanged is one
     * the ledger can hold exactly. The comparison runs at `scale + 6` because
     * comparing at `$scale` is precisely the blindness being closed: at EUR's
     * scale 2, `0.005` and `0.00` compare EQUAL.
     *
     * @param  numeric-string  $amount
     * @return numeric-string
     *
     * @throws UnpostableCorrectingEntryException
     */
    private function restateLegAmount(
        Document $correctingEntry,
        string $accountId,
        string $amount,
        int $scale,
    ): string {
        /** @var numeric-string $restated */
        $restated = CurrencyScale::bcformatStrict($amount, $scale);

        if (bccomp($amount, $restated, $scale + 6) !== 0) {
            throw UnpostableCorrectingEntryException::legAmountBeyondCurrencyScale(
                $correctingEntry->document_number,
                $accountId,
                $amount,
                $correctingEntry->currency,
                $scale,
            );
        }

        return $restated;
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
