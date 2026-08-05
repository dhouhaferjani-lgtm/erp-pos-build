<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\JournalEntryCreated;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\DoubleEntryValidator;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
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
final class AccountingService implements AccountingServiceInterface
{
    public function __construct(
        private readonly GeneralLedgerHashService $hashService,
        private readonly PartnerBalanceService $partnerBalanceService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly DoubleEntryValidator $doubleEntryValidator,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * W-6 D1a — refuse to seal a journal entry whose Sigma(debits) != Sigma(credits).
     *
     * The document-sourced GL paths auto-post: they create the entry as `Posted`
     * and hash-chain it in the same transaction, and `verifyChain()` never asserts
     * the balance invariant, so an unbalanced entry is immutable AND invisible to
     * the compliance tooling. Called after every line is written and BEFORE the
     * fiscal hash is computed, so the throw rolls the whole transaction back.
     *
     * Comparison is bcmath at the currency scale via the same `DoubleEntryValidator`
     * that guards the manual route (`JournalEntryController::store()` /
     * `UNBALANCED_ENTRY`) — never a float. `isBalanced()` also rejects a
     * degenerate single-line entry, which a document posting can never legitimately
     * produce (AR leg + at least one revenue leg).
     *
     * @throws UnbalancedJournalEntryException
     */
    private function assertBalanced(JournalEntry $entry, string $entryType, ?string $documentNumber): void
    {
        /** @var array<int, array{debit: string, credit: string}> $lines */
        $lines = $entry->lines
            ->map(static fn (JournalLine $line): array => [
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
            ])
            ->values()
            ->all();

        if ($this->doubleEntryValidator->isBalanced($lines)) {
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
            $totalDebits = bcadd($totalDebits, $debit, $this->scale());
            $totalCredits = bcadd($totalCredits, $credit, $this->scale());
        }

        throw UnbalancedJournalEntryException::forSourceDocument(
            $entryType,
            $documentNumber ?? $entry->entry_number,
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
        $entryId = DB::transaction(function () use ($invoice): string {
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
            /** @var numeric-string $revenueCredited */
            $revenueCredited = '0';
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
                $revenueCredited = bcadd($revenueCredited, $lineTotal, $this->scale());
            }

            // 3. Create VAT credit lines (grouped by tax rate)
            /** @var numeric-string $vatCredited */
            $vatCredited = '0';
            $taxByRate = $this->groupTaxByRate($invoice->lines);
            foreach ($taxByRate as $rate => $amount) {
                if (bccomp($amount, '0', $this->scale()) > 0) {
                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $vatCollectedAccount->id,
                        'debit' => '0',
                        'credit' => $amount,
                        'description' => 'VAT '.$rate.'% from Invoice '.$invoice->document_number,
                    ]);
                    $vatCredited = bcadd($vatCredited, $amount, $this->scale());
                }
            }

            // 3b. Document-level stamp duty (Tunisian timbre / droit de timbre) is
            // the residual of total − revenue − line VAT. It rides in the AR debit
            // (invoice total) but is NOT a line VAT and must NOT be lumped into the
            // VAT account; credit it to the dedicated collected-stamp-duty liability
            // (4375) so it is remitted to the State. Without this leg the AR debit
            // carried the timbre uncredited and every TN invoice posted an
            // unbalanced journal entry (bug #5A).
            /** @var numeric-string $stampDuty */
            $stampDuty = bcsub((string) ($invoice->total ?? '0'), bcadd($revenueCredited, $vatCredited, $this->scale()), $this->scale());
            $stampDutyAccount = Account::findByPurpose($invoice->company_id, SystemAccountPurpose::SalesStampDutyPayable);
            if (bccomp($stampDuty, '0', $this->scale()) > 0 && $stampDutyAccount !== null) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $stampDutyAccount->id,
                    'debit' => '0',
                    'credit' => $stampDuty,
                    'description' => 'Stamp duty (timbre) from Invoice '.$invoice->document_number,
                ]);
            }

            // 4. Calculate and set fiscal_hash AFTER lines are created
            $freshEntry = $entry->fresh(['lines']);
            if ($freshEntry === null) {
                throw new \RuntimeException('Failed to reload journal entry after creation');
            }

            // 4a. W-6 D1a — double-entry guard. This entry was created `Posted` and
            // is sealed into the GL hash chain on the next line; `verifyChain()`
            // checks linkage and hash recomputation but NEVER the balance
            // invariant, so an unbalanced entry would pass compliance verification
            // forever. Step 3b only credits a POSITIVE residual, so a header total
            // that under-runs the recomputed line tax silently discarded the
            // difference. Fail CLOSED: throwing aborts the surrounding transaction,
            // so nothing persists and no chain sequence is consumed.
            $this->assertBalanced($freshEntry, 'invoice', $invoice->document_number);

            $hash = $this->hashService->calculateHash($freshEntry, $previousHash);
            $entry->update(['fiscal_hash' => $hash]);

            // Dispatch JournalEntryCreated event for audit trail
            $entry = $entry->fresh(['lines']);
            if ($entry === null) {
                throw new \RuntimeException('Failed to reload journal entry after hash update');
            }

            $this->dispatchJournalEntryCreatedEvent($entry, 'invoice');

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
        $entryId = DB::transaction(function () use ($creditNote): string {
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
            /** @var numeric-string $revenueDebited */
            $revenueDebited = '0';
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
                $revenueDebited = bcadd($revenueDebited, $lineTotal, $this->scale());
            }

            // 3. Create VAT debit lines (grouped by tax rate) - REVERSED from invoice
            /** @var numeric-string $vatDebited */
            $vatDebited = '0';
            $taxByRate = $this->groupTaxByRate($creditNote->lines);
            foreach ($taxByRate as $rate => $amount) {
                if (bccomp($amount, '0', $this->scale()) > 0) {
                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $vatCollectedAccount->id,
                        'debit' => $amount,
                        'credit' => '0',
                        'description' => 'VAT reversal '.$rate.'% from Credit Note '.$creditNote->document_number,
                    ]);
                    $vatDebited = bcadd($vatDebited, $amount, $this->scale());
                }
            }

            // 3b. Reverse the collected stamp duty (timbre): the residual of
            // total − revenue − line VAT rides in the AR credit and must be
            // debited back out of the collected-stamp-duty liability (4375),
            // mirroring the invoice posting (bug #5A). Without it the credit note
            // posts an unbalanced reversal.
            /** @var numeric-string $stampDuty */
            $stampDuty = bcsub((string) ($creditNote->total ?? '0'), bcadd($revenueDebited, $vatDebited, $this->scale()), $this->scale());
            $stampDutyAccount = Account::findByPurpose($creditNote->company_id, SystemAccountPurpose::SalesStampDutyPayable);
            if (bccomp($stampDuty, '0', $this->scale()) > 0 && $stampDutyAccount !== null) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $stampDutyAccount->id,
                    'debit' => $stampDuty,
                    'credit' => '0',
                    'description' => 'Stamp duty (timbre) reversal from Credit Note '.$creditNote->document_number,
                ]);
            }

            // 4. Calculate and set fiscal_hash AFTER lines are created
            $freshEntry = $entry->fresh(['lines']);
            if ($freshEntry === null) {
                throw new \RuntimeException('Failed to reload journal entry after creation');
            }

            // 4a. W-6 D1a (credit-note sibling) — the reversal repeats the invoice
            // pattern line-for-line, so it carries the same guard for the same
            // reason: an unbalanced entry sealed into the immutable GL hash chain
            // is invisible to `verifyChain()` and cannot be edited back out.
            $this->assertBalanced($freshEntry, 'credit note', $creditNote->document_number);

            $hash = $this->hashService->calculateHash($freshEntry, $previousHash);
            $entry->update(['fiscal_hash' => $hash]);

            // Dispatch JournalEntryCreated event for audit trail
            $entry = $entry->fresh(['lines']);
            if ($entry === null) {
                throw new \RuntimeException('Failed to reload journal entry after hash update');
            }

            $this->dispatchJournalEntryCreatedEvent($entry, 'credit_note');

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
     * @param  Collection<int, DocumentLine>  $lines
     * @return array<numeric-string, numeric-string> Tax rate => Total tax amount
     */
    private function groupTaxByRate($lines): array
    {
        $taxByRate = [];

        foreach ($lines as $line) {
            $taxRate = $line->tax_rate ?? '0';

            // Calculate tax amount for this line
            $taxAmount = bcmul(
                $line->line_total,
                bcdiv($taxRate, '100', 4),
                $this->scale()
            );

            // Add to the rate's total
            if (! isset($taxByRate[$taxRate])) {
                $taxByRate[$taxRate] = '0';
            }

            $taxByRate[$taxRate] = bcadd($taxByRate[$taxRate], $taxAmount, $this->scale());
        }

        return $taxByRate;
    }

    /**
     * Dispatch JournalEntryCreated event for audit trail.
     *
     * @param  JournalEntry  $entry  The journal entry (with lines loaded)
     * @param  string  $entryType  The type of entry (invoice, credit_note, etc.)
     */
    private function dispatchJournalEntryCreatedEvent(JournalEntry $entry, string $entryType): void
    {
        // Calculate total debits and credits from lines
        $totalDebit = '0';
        $totalCredit = '0';

        foreach ($entry->lines as $line) {
            $totalDebit = bcadd($totalDebit, $line->debit, $this->scale());
            $totalCredit = bcadd($totalCredit, $line->credit, $this->scale());
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
