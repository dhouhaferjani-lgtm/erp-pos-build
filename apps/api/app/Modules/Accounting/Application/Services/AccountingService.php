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
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\DoubleEntryValidator;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Shared\Contracts\Accounting\DocumentGlPreflightInterface;
use App\Shared\Contracts\AccountingServiceInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Application service for accounting operations.
 *
 * Exposes accounting functionality to other modules through the AccountingServiceInterface.
 */
final class AccountingService implements AccountingServiceInterface, DocumentGlPreflightInterface
{
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
     *   - otherwise the `SalesRoundingDifference*` account, but only up to what
     *     per-line tax truncation can explain: one unit of the last place per line.
     *     A chart with no document-level charge concept has no honest reason for a
     *     bigger gap, and burying real money in a rounding account would be worse
     *     than refusing.
     *   - no absorbing account at all → REFUSE rather than silently drop it.
     * - `residual == 0` → nothing to book.
     */
    private function residualPlan(Document $document, int $scale): DocumentGlResidualPlan
    {
        // A document with NO lines has no revenue side at all: the posting writes a
        // lone AR leg and `residual == total`, which is not a rounding artefact and
        // not the D1a defect either — it is a separate, PRE-EXISTING broken shape.
        // It is unreachable through the documented API (`CreateDocumentRequest`
        // requires `lines` min:1) and survives only in legacy/test fixtures, so this
        // lane deliberately leaves its behaviour untouched rather than change ~35
        // call sites under a merge gate. Ticketed:
        // docs/superpowers/tickets/2026-08-05-lineless-document-gl-posting.md
        if ($document->lines->isEmpty()) {
            /** @var numeric-string $total */
            $total = (string) ($document->total ?? '0');

            return new DocumentGlResidualPlan('0', '0', $total, [], null, null, false);
        }

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
        /** @var numeric-string $residual */
        $residual = bcsub($documentTotal, bcadd($revenue, $vat, $scale), $scale);

        $comparison = bccomp($residual, '0', $scale);

        if ($comparison === 0) {
            return new DocumentGlResidualPlan($revenue, $vat, $residual, $taxByRate, null, null);
        }

        if ($comparison < 0) {
            return new DocumentGlResidualPlan(
                $revenue, $vat, $residual, $taxByRate, null, GlResidualRefusal::NegativeResidual,
            );
        }

        $stampDutyAccount = Account::findByPurpose($document->company_id, SystemAccountPurpose::SalesStampDutyPayable);
        if ($stampDutyAccount !== null) {
            return new DocumentGlResidualPlan($revenue, $vat, $residual, $taxByRate, $stampDutyAccount, null);
        }

        $roundingPurpose = $document->type === DocumentType::CreditNote
            ? SystemAccountPurpose::SalesRoundingDifferenceExpense
            : SystemAccountPurpose::SalesRoundingDifferenceIncome;
        $roundingAccount = Account::findByPurpose($document->company_id, $roundingPurpose);

        if ($roundingAccount === null) {
            return new DocumentGlResidualPlan(
                $revenue, $vat, $residual, $taxByRate, null, GlResidualRefusal::NoAbsorbingAccount,
            );
        }

        if (bccomp($residual, $this->roundingTolerance($document, $scale), $scale) > 0) {
            return new DocumentGlResidualPlan(
                $revenue, $vat, $residual, $taxByRate, null, GlResidualRefusal::ResidualExceedsRoundingTolerance,
            );
        }

        return new DocumentGlResidualPlan($revenue, $vat, $residual, $taxByRate, $roundingAccount, null);
    }

    /**
     * How large a POSITIVE residual per-line tax truncation alone can produce:
     * one unit of the last place per line.
     *
     * `groupTaxByRate()` truncates each line's tax (`bcmul(..., $scale)`), while
     * `TaxCalculationService` accumulates at `scale+1` and truncates once per rate
     * bucket. Since `Σ trunc(xᵢ) <= trunc(Σ xᵢ)`, the header tax can exceed the GL
     * VAT by at most one ULP per line.
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
     * Create an opening balance journal entry.
     *
     * @return string The journal entry ID
     */
    public function createOpeningBalanceEntry(
        string $tenantId,
        string $companyId,
        string $accountId,
        string $debit,
        string $credit,
        string $description,
        ?string $reference,
        DateTimeInterface $date
    ): string {
        $entryNumber = 'OB-'.$date->format('YmdHis').'-'.random_int(1000, 9999);
        $fullDescription = $description.($reference !== null && $reference !== '' ? ' - '.$reference : '');

        $entry = JournalEntry::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'entry_number' => $entryNumber,
            'entry_date' => $date,
            'description' => $fullDescription,
            'status' => JournalEntryStatus::Posted,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
        ]);

        return $entry->id;
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
                'source_type' => 'Document',
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
                'source_type' => 'Document',
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

            // 1. Create AR credit line (full credit note total) - REVERSED from invoice
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $arAccount->id,
                'partner_id' => $creditNote->partner_id,
                'debit' => '0',
                'credit' => $creditNote->total,
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

            // 3b. Reverse the residual out: total − revenue − line VAT rides in the
            // AR credit and needs a debit leg, mirroring the invoice posting. On the
            // Tunisian chart that is the collected timbre going back out of the 4375
            // liability; elsewhere it is the tax-rounding difference. `residualPlan()`
            // chose the account and `DocumentPostingService` pre-flighted the verdict
            // before the credit note was sealed.
            if ($plan->absorbingAccount !== null) {
                $isStampDuty = $plan->absorbingAccount->system_purpose === SystemAccountPurpose::SalesStampDutyPayable;
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $plan->absorbingAccount->id,
                    'debit' => $plan->residual,
                    'credit' => '0',
                    'description' => ($isStampDuty ? 'Stamp duty (timbre) reversal' : 'Tax rounding difference reversal')
                        .' from Credit Note '.$creditNote->document_number,
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
