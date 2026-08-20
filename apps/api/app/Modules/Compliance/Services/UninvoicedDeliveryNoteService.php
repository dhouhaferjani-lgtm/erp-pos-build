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
use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
        /** @var Company $company */
        $company = Company::query()->findOrFail($companyId);

        $query = $this->baseUninvoicedQuery($company, $fromDate, $toDate)
            ->with('partner:id,name');

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
     * Build the partner-grouped, company-currency work queue.
     *
     * Pagination is deliberately applied after grouping: one page unit is one
     * complete partner group, never an arbitrary slice of delivery notes.
     *
     * @return array{
     *   data: list<array{partner_id: string, partner_name: string, partner_code: string|null, delivery_note_count: int, total: numeric-string, currency: string, oldest_document_date: string, aging_bucket: string, is_periodic: bool}>,
     *   meta: array{current_page: int, last_page: int, total: int, per_page: int},
     *   summary: array{buckets: list<array{bucket: string, count: int, total: numeric-string}>, grand_total: numeric-string, grand_count: int, currency: string}
     * }
     */
    public function getToBillSummary(
        string $companyId,
        ?string $locationId,
        ?Carbon $fromDate,
        ?Carbon $toDate,
        ?string $partnerSearch,
        bool $periodicOnly,
        int $page,
        int $perPage,
    ): array {
        /** @var Company $company */
        $company = Company::query()->findOrFail($companyId);
        $scale = $this->scaleResolver->getScale($company->currency);

        $groupRows = $this->queueQuery(
            $company,
            $locationId,
            $fromDate,
            $toDate,
            $partnerSearch,
            $periodicOnly,
        )
            ->select('documents.partner_id')
            ->selectRaw('COUNT(documents.id) as delivery_note_count')
            ->selectRaw('SUM(documents.total) as aggregate_total')
            ->selectRaw('MIN(documents.document_date) as oldest_document_date')
            ->groupBy('documents.partner_id')
            ->orderByRaw('MIN(documents.document_date) ASC')
            ->orderBy('documents.partner_id')
            ->get();
        $partners = Partner::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereIn('id', $groupRows->pluck('partner_id')->all())
            ->get(['id', 'name', 'code', 'invoice_consolidation'])
            ->keyBy('id');

        $groups = $groupRows
            ->map(function (Document $group) use ($company, $partners, $scale): array {
                $partner = $partners->get((string) $group->getAttribute('partner_id'));
                if (! $partner instanceof Partner) {
                    throw new \LogicException('A to-bill group must resolve its customer partner.');
                }
                $oldestDate = Carbon::parse((string) $group->getAttribute('oldest_document_date'));
                $total = CurrencyScale::bcformatStrict(
                    (string) ($group->getAttribute('aggregate_total') ?? '0'),
                    $scale,
                );

                return [
                    'partner_id' => (string) $group->getAttribute('partner_id'),
                    'partner_name' => $partner->name,
                    'partner_code' => $partner->code !== null
                        ? $partner->code
                        : null,
                    'delivery_note_count' => (int) $group->getAttribute('delivery_note_count'),
                    'total' => $total,
                    'currency' => $company->currency,
                    'oldest_document_date' => $oldestDate->toDateString(),
                    'aging_bucket' => $this->agingBucket($oldestDate, $company->timezone),
                    'is_periodic' => $partner->invoice_consolidation,
                ];
            })
            ->values();

        $emptyTotal = CurrencyScale::bcformatStrict('0', $scale);
        /** @var array{'0_30': array{count: int, total: numeric-string}, '31_60': array{count: int, total: numeric-string}, '61_90': array{count: int, total: numeric-string}, '90_plus': array{count: int, total: numeric-string}} $bucketTotals */
        $bucketTotals = [
            '0_30' => ['count' => 0, 'total' => $emptyTotal],
            '31_60' => ['count' => 0, 'total' => $emptyTotal],
            '61_90' => ['count' => 0, 'total' => $emptyTotal],
            '90_plus' => ['count' => 0, 'total' => $emptyTotal],
        ];
        $grandTotal = $emptyTotal;

        foreach ($groups as $group) {
            $bucket = $group['aging_bucket'];
            $bucketTotals[$bucket]['count']++;
            $bucketTotals[$bucket]['total'] = bcadd($bucketTotals[$bucket]['total'], $group['total'], $scale);
            $grandTotal = bcadd($grandTotal, $group['total'], $scale);
        }

        $totalGroups = $groups->count();
        $lastPage = max(1, (int) ceil($totalGroups / $perPage));
        $pageData = array_values($groups->slice(($page - 1) * $perPage, $perPage)->values()->all());
        $buckets = [];
        foreach ($bucketTotals as $bucket => $totals) {
            $buckets[] = [
                'bucket' => $bucket,
                'count' => $totals['count'],
                'total' => CurrencyScale::bcformatStrict($totals['total'], $scale),
            ];
        }

        return [
            'data' => $pageData,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $totalGroups,
                'per_page' => $perPage,
            ],
            'summary' => [
                'buckets' => $buckets,
                'grand_total' => CurrencyScale::bcformatStrict($grandTotal, $scale),
                'grand_count' => $totalGroups,
                'currency' => $company->currency,
            ],
        ];
    }

    /**
     * Return the lazily expanded rows for one queue group.
     *
     * @return array{
     *   data: list<array{id: string, document_number: string, document_date: string, partner_id: string, partner_name: string, subtotal: string, tax_amount: string, total: string, currency: string}>,
     *   meta: array{current_page: int, last_page: int, total: int, per_page: int},
     *   summary: array{count: int, total: numeric-string, currency: string}
     * }
     */
    public function getToBillPartnerRows(
        string $companyId,
        string $partnerId,
        ?string $locationId,
        ?Carbon $fromDate,
        ?Carbon $toDate,
        ?string $partnerSearch,
        bool $periodicOnly,
        int $page,
        int $perPage,
    ): array {
        /** @var Company $company */
        $company = Company::query()->findOrFail($companyId);
        $scale = $this->scaleResolver->getScale($company->currency);
        $query = $this->queueQuery(
            $company,
            $locationId,
            $fromDate,
            $toDate,
            $partnerSearch,
            $periodicOnly,
        )->where('partner_id', $partnerId);

        $count = (clone $query)->count();
        $total = CurrencyScale::bcformatStrict((string) (clone $query)->sum('total'), $scale);
        $paginator = $query
            ->with('partner:id,name')
            ->orderBy('document_date')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = array_values($paginator->getCollection()->map(function (Document $dn): array {
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
                'currency' => $dn->currency,
            ];
        })->all());

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'summary' => [
                'count' => $count,
                'total' => $total,
                'currency' => $company->currency,
            ],
        ];
    }

    /**
     * @return Builder<Document>
     */
    private function queueQuery(
        Company $company,
        ?string $locationId,
        ?Carbon $fromDate,
        ?Carbon $toDate,
        ?string $partnerSearch,
        bool $periodicOnly,
    ): Builder {
        $query = $this->baseUninvoicedQuery($company, $fromDate, $toDate)
            ->where('documents.currency', $company->currency)
            ->whereHas('partner', function ($partnerQuery) use ($company, $partnerSearch, $periodicOnly): void {
                $partnerQuery
                    ->whereRaw('partners.tenant_id = ?', [$company->tenant_id])
                    ->whereRaw('partners.company_id = ?', [$company->id])
                    ->whereRaw('partners.type IN (?, ?)', ['customer', 'both']);

                if ($partnerSearch !== null) {
                    $needle = '%'.mb_strtolower($partnerSearch).'%';
                    $partnerQuery->where(function ($searchQuery) use ($needle): void {
                        $searchQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(code) LIKE ?', [$needle]);
                    });
                }

                if ($periodicOnly) {
                    $partnerQuery->whereRaw('partners.invoice_consolidation = ?', [true]);
                }
            });

        if ($locationId !== null) {
            $query->where('documents.location_id', $locationId);
        }

        return $query;
    }

    /**
     * @return Builder<Document>
     */
    private function baseUninvoicedQuery(
        Company $company,
        ?Carbon $fromDate,
        ?Carbon $toDate,
    ): Builder {
        $query = Document::query()
            // The explicit predicate protects shared-DB compatibility mode; in
            // DB-per-tenant mode Stancl has already switched this query to the
            // active tenant connection.
            ->forTenant($company->tenant_id)
            ->where('documents.company_id', $company->id)
            ->where('documents.type', DocumentType::DeliveryNote)
            ->where('documents.status', DocumentStatus::Confirmed)
            ->whereDeliveryNoteUninvoiced();

        if ($fromDate !== null) {
            $query->where('documents.document_date', '>=', $fromDate->copy()->startOfDay());
        }

        if ($toDate !== null) {
            $query->where('documents.document_date', '<=', $toDate->copy()->endOfDay());
        }

        return $query;
    }

    /** @return '0_30'|'31_60'|'61_90'|'90_plus' */
    private function agingBucket(Carbon $oldestDate, string $timezone): string
    {
        $ageInDays = (int) $oldestDate->copy()->startOfDay()->diffInDays(Carbon::now($timezone)->startOfDay());

        return match (true) {
            $ageInDays <= 30 => '0_30',
            $ageInDays <= 60 => '31_60',
            $ageInDays <= 90 => '61_90',
            default => '90_plus',
        };
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
