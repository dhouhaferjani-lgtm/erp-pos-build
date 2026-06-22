<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\JournalEntryCreated;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Shared\Contracts\AccountingServiceInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

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
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
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

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'debit' => '0',
                'credit' => $line->line_total,
                'description' => 'Revenue from Invoice '.$invoice->document_number.' - Line '.$line->line_number,
            ]);
        }

        // 3. Create VAT credit lines (grouped by tax rate)
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
            }
        }

        // 4. Calculate and set fiscal_hash AFTER lines are created
        $freshEntry = $entry->fresh(['lines']);
        if ($freshEntry === null) {
            throw new \RuntimeException('Failed to reload journal entry after creation');
        }

        $hash = $this->hashService->calculateHash($freshEntry, $previousHash);
        $entry->update(['fiscal_hash' => $hash]);

        // Dispatch JournalEntryCreated event for audit trail
        $entry = $entry->fresh(['lines']);
        if ($entry === null) {
            throw new \RuntimeException('Failed to reload journal entry after hash update');
        }

        $this->dispatchJournalEntryCreatedEvent($entry, 'invoice');

        // Refresh cached partner balance after GL entry creation
        $this->partnerBalanceService->refreshPartnerBalance(
            $invoice->company_id,
            $invoice->partner_id
        );

        return $entry->id;
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

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'debit' => $line->line_total,
                'credit' => '0',
                'description' => 'Revenue reversal from Credit Note '.$creditNote->document_number.' - Line '.$line->line_number,
            ]);
        }

        // 3. Create VAT debit lines (grouped by tax rate) - REVERSED from invoice
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
            }
        }

        // 4. Calculate and set fiscal_hash AFTER lines are created
        $freshEntry = $entry->fresh(['lines']);
        if ($freshEntry === null) {
            throw new \RuntimeException('Failed to reload journal entry after creation');
        }

        $hash = $this->hashService->calculateHash($freshEntry, $previousHash);
        $entry->update(['fiscal_hash' => $hash]);

        // Dispatch JournalEntryCreated event for audit trail
        $entry = $entry->fresh(['lines']);
        if ($entry === null) {
            throw new \RuntimeException('Failed to reload journal entry after hash update');
        }

        $this->dispatchJournalEntryCreatedEvent($entry, 'credit_note');

        // Refresh cached partner balance after GL entry creation
        $this->partnerBalanceService->refreshPartnerBalance(
            $creditNote->company_id,
            $creditNote->partner_id
        );

        return $entry->id;
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
