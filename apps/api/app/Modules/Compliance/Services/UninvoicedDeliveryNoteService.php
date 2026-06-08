<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Service for handling year-end uninvoiced delivery note reporting.
 *
 * Tunisia/France accounting requires all delivery notes to have matching
 * invoices by fiscal year end. This service provides:
 * - Listing uninvoiced DNs for a company
 * - Calculating totals for year-end adjustment entries
 * - Generating adjustment journal entries (account 418)
 * - Generating reversal entries for new fiscal year
 */
class UninvoicedDeliveryNoteService
{
    public function __construct(
        private readonly ChartOfAccountsService $accountsService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Resolve the monetary scale from a company's own currency.
     *
     * This service is invoked from year-end reporting/adjustment paths that may
     * run outside an HTTP request (console commands / scheduled jobs), where no
     * CompanyContext is bound. Passing the company currency explicitly is both
     * context-safe AND fiscally correct (EUR→2, TND→3).
     */
    private function scaleFor(string $companyId): int
    {
        /** @var Company $company */
        $company = Company::findOrFail($companyId);

        return $this->scaleResolver->getScale($company->currency);
    }

    /**
     * Get all uninvoiced delivery notes for a company.
     *
     * @return array<int, array{id: string, document_number: string, document_date: string, partner_id: string, partner_name: string, subtotal: string, tax_amount: string, total: string}>
     */
    public function getUninvoicedDeliveryNotes(
        string $companyId,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null,
    ): array {
        $query = Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::DeliveryNote)
            ->where('status', DocumentStatus::Confirmed)
            ->where(function ($q) {
                $q->whereNull('payload->invoiced_at')
                    ->orWhereJsonContains('payload', ['invoiced_at' => null]);
            })
            ->with('partner:id,name');

        if ($fromDate !== null) {
            $query->where('document_date', '>=', $fromDate->startOfDay());
        }

        if ($toDate !== null) {
            $query->where('document_date', '<=', $toDate->endOfDay());
        }

        return $query
            ->orderBy('document_date')
            ->get()
            ->map(function (Document $dn): array {
                /** @var numeric-string $subtotal */
                $subtotal = $dn->subtotal ?? '0.00';
                /** @var numeric-string $taxAmount */
                $taxAmount = $dn->tax_amount ?? '0.00';
                /** @var numeric-string $total */
                $total = $dn->total ?? '0.00';

                return [
                    'id' => $dn->id,
                    'document_number' => $dn->document_number,
                    'document_date' => $dn->document_date->toDateString(),
                    'partner_id' => $dn->partner_id,
                    'partner_name' => $dn->partner->name ?? '',
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                ];
            })
            ->all();
    }

    /**
     * Calculate totals for all uninvoiced delivery notes.
     *
     * @return array{subtotal: string, tax_amount: string, total: string, count: int}
     */
    public function calculateUninvoicedTotals(
        string $companyId,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null,
    ): array {
        $dns = $this->getUninvoicedDeliveryNotes($companyId, $fromDate, $toDate);

        $scale = $this->scaleFor($companyId);

        /** @var numeric-string $subtotal */
        $subtotal = '0.00';
        /** @var numeric-string $taxAmount */
        $taxAmount = '0.00';
        /** @var numeric-string $total */
        $total = '0.00';

        foreach ($dns as $dn) {
            $subtotal = bcadd($subtotal, $dn['subtotal'], $scale); // @phpstan-ignore argument.type
            $taxAmount = bcadd($taxAmount, $dn['tax_amount'], $scale); // @phpstan-ignore argument.type
            $total = bcadd($total, $dn['total'], $scale); // @phpstan-ignore argument.type
        }

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'count' => count($dns),
        ];
    }

    /**
     * Generate a comprehensive year-end report for uninvoiced delivery notes.
     *
     * @return array{
     *     company_id: string,
     *     generated_at: string,
     *     uninvoiced_delivery_notes: array<int, array{id: string, document_number: string, document_date: string, partner_id: string, partner_name: string, subtotal: string, tax_amount: string, total: string}>,
     *     totals: array{subtotal: string, tax_amount: string, total: string, count: int},
     *     by_partner: array<string, array{partner_id: string, partner_name: string, subtotal: string, tax_amount: string, total: string, count: int, delivery_notes: list<mixed>}>
     * }
     */
    public function generateYearEndReport(
        string $companyId,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null,
    ): array {
        $dns = $this->getUninvoicedDeliveryNotes($companyId, $fromDate, $toDate);
        $totals = $this->calculateUninvoicedTotals($companyId, $fromDate, $toDate);

        $scale = $this->scaleFor($companyId);

        // Group by partner
        /** @var array<string, array{partner_id: string, partner_name: string, subtotal: string, tax_amount: string, total: string, count: int, delivery_notes: list<mixed>}> $byPartner */
        $byPartner = [];
        foreach ($dns as $dn) {
            $partnerId = $dn['partner_id'];
            if (! isset($byPartner[$partnerId])) {
                $byPartner[$partnerId] = [
                    'partner_id' => $partnerId,
                    'partner_name' => $dn['partner_name'],
                    'subtotal' => '0.00',
                    'tax_amount' => '0.00',
                    'total' => '0.00',
                    'count' => 0,
                    'delivery_notes' => [],
                ];
            }

            /** @var numeric-string $currentSubtotal */
            $currentSubtotal = $byPartner[$partnerId]['subtotal'];
            /** @var numeric-string $currentTaxAmount */
            $currentTaxAmount = $byPartner[$partnerId]['tax_amount'];
            /** @var numeric-string $currentTotal */
            $currentTotal = $byPartner[$partnerId]['total'];

            $byPartner[$partnerId]['subtotal'] = bcadd($currentSubtotal, $dn['subtotal'], $scale); // @phpstan-ignore argument.type
            $byPartner[$partnerId]['tax_amount'] = bcadd($currentTaxAmount, $dn['tax_amount'], $scale); // @phpstan-ignore argument.type
            $byPartner[$partnerId]['total'] = bcadd($currentTotal, $dn['total'], $scale); // @phpstan-ignore argument.type
            $byPartner[$partnerId]['count']++;
            $byPartner[$partnerId]['delivery_notes'][] = $dn;
        }

        return [
            'company_id' => $companyId,
            'generated_at' => now()->toIso8601String(),
            'uninvoiced_delivery_notes' => $dns,
            'totals' => $totals,
            'by_partner' => $byPartner,
        ];
    }

    /**
     * Generate year-end adjustment journal entry for uninvoiced delivery notes.
     *
     * Creates:
     * - Debit: 418 - Clients, produits non encore facturés (UninvoicedRevenue)
     * - Credit: 70x - Ventes (ProductRevenue)
     *
     * @return JournalEntry|null Returns null if no uninvoiced DNs exist
     *
     * @throws ModelNotFoundException If required accounts are not configured
     */
    public function generateYearEndAdjustment(
        string $companyId,
        Carbon $adjustmentDate,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null,
    ): ?JournalEntry {
        $totals = $this->calculateUninvoicedTotals($companyId, $fromDate, $toDate);

        /** @var numeric-string $zeroAmount */
        $zeroAmount = '0.00';

        // No adjustment needed if no uninvoiced DNs
        if (bccomp($totals['total'], $zeroAmount, $this->scaleFor($companyId)) === 0) { // @phpstan-ignore argument.type
            return null;
        }

        // Get the required accounts (throws ModelNotFoundException if not found)
        $uninvoicedAccount = $this->accountsService->getAccountByPurpose(
            $companyId,
            SystemAccountPurpose::UninvoicedRevenue
        );

        $revenueAccount = $this->accountsService->getAccountByPurpose(
            $companyId,
            SystemAccountPurpose::ProductRevenue
        );

        $company = Company::findOrFail($companyId);

        return DB::transaction(function () use ($company, $uninvoicedAccount, $revenueAccount, $totals, $adjustmentDate): JournalEntry {
            // Generate entry number
            $lastEntry = JournalEntry::where('company_id', $company->id)
                ->whereYear('entry_date', $adjustmentDate->year)
                ->orderByDesc('entry_number')
                ->first();

            $nextNumber = $lastEntry
                ? (int) substr($lastEntry->entry_number, -6) + 1
                : 1;

            $entryNumber = sprintf('JE-%d-%06d', $adjustmentDate->year, $nextNumber);

            // Create the journal entry
            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'entry_number' => $entryNumber,
                'entry_date' => $adjustmentDate,
                'description' => 'Year-end adjustment for uninvoiced delivery notes',
                'source_type' => 'uninvoiced_dn_adjustment',
                'source_id' => null,
                'status' => JournalEntryStatus::Draft,
            ]);

            // Debit: Uninvoiced Revenue (418)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $uninvoicedAccount->id,
                'debit' => $totals['total'],
                'credit' => '0.00',
                'description' => 'Uninvoiced delivery notes at year-end',
            ]);

            // Credit: Product Revenue (70x)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'debit' => '0.00',
                'credit' => $totals['total'],
                'description' => 'Accrued revenue for uninvoiced delivery notes',
            ]);

            /** @var JournalEntry $freshEntry */
            $freshEntry = $entry->fresh(['lines']);

            return $freshEntry;
        });
    }

    /**
     * Generate reversal entry for a year-end adjustment.
     *
     * This creates the opposite entry at the start of the new fiscal year.
     */
    public function generateReversalEntry(
        JournalEntry $originalEntry,
        Carbon $reversalDate,
    ): JournalEntry {
        $company = Company::findOrFail($originalEntry->company_id);

        return DB::transaction(function () use ($company, $originalEntry, $reversalDate): JournalEntry {
            // Generate entry number
            $lastEntry = JournalEntry::where('company_id', $company->id)
                ->whereYear('entry_date', $reversalDate->year)
                ->orderByDesc('entry_number')
                ->first();

            $nextNumber = $lastEntry
                ? (int) substr($lastEntry->entry_number, -6) + 1
                : 1;

            $entryNumber = sprintf('JE-%d-%06d', $reversalDate->year, $nextNumber);

            // Create the reversal entry
            $reversalEntry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'entry_number' => $entryNumber,
                'entry_date' => $reversalDate,
                'description' => 'Reversal of year-end adjustment for uninvoiced delivery notes',
                'source_type' => 'uninvoiced_dn_reversal',
                'source_id' => $originalEntry->id,
                'status' => JournalEntryStatus::Draft,
            ]);

            // Reverse each line (swap debits and credits)
            foreach ($originalEntry->lines as $originalLine) {
                JournalLine::create([
                    'journal_entry_id' => $reversalEntry->id,
                    'account_id' => $originalLine->account_id,
                    'debit' => $originalLine->credit,
                    'credit' => $originalLine->debit,
                    'description' => 'Reversal: '.$originalLine->description,
                ]);
            }

            /** @var JournalEntry $freshEntry */
            $freshEntry = $reversalEntry->fresh(['lines']);

            return $freshEntry;
        });
    }
}
