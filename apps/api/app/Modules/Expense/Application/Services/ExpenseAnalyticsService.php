<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Application\DTOs\AnalyticsFilters;
use App\Modules\Expense\Application\DTOs\ExpenseAnalyticsCategoryData;
use App\Modules\Expense\Application\DTOs\ExpenseAnalyticsData;
use App\Modules\Expense\Application\DTOs\ExpenseAnalyticsMatrixData;
use App\Modules\Expense\Application\DTOs\ExpenseAnalyticsTilesData;
use App\Modules\Expense\Application\DTOs\ExpenseAnalyticsVendorData;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class ExpenseAnalyticsService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function generate(string $tenantId, string $companyId, AnalyticsFilters $filters): ExpenseAnalyticsData
    {
        $company = Company::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($companyId)
            ->firstOrFail();
        $scale = $this->scaleResolver->getScale($company->currency);

        $current = $this->totals($tenantId, $companyId, $filters, $scale);
        $priorFilters = $this->priorPeriod($filters);
        $previous = $this->totals($tenantId, $companyId, $priorFilters, $scale);

        $momDelta = null;
        if (bccomp($previous['total'], '0', $scale) !== 0) {
            $intermediate = $scale + 4;
            $change = bcsub($current['total'], $previous['total'], $intermediate);
            $ratio = bcdiv($change, $previous['total'], $intermediate);
            $momDelta = CurrencyScale::bcround(bcmul($ratio, '100', $intermediate), 2);
        }

        return new ExpenseAnalyticsData(
            tiles: new ExpenseAnalyticsTilesData(
                total: $current['total'],
                count: $current['count'],
                unpaid_total: $current['unpaid_total'],
                mom_delta_percent: $momDelta,
            ),
            by_category: $this->byCategory($tenantId, $companyId, $filters, $current['total'], $scale),
            matrix: $this->matrix($tenantId, $companyId, $filters, $scale),
            top_vendors: $this->topVendors($tenantId, $companyId, $filters, $scale),
        );
    }

    /** @return array{total: numeric-string, count: int, unpaid_total: numeric-string} */
    private function totals(
        string $tenantId,
        string $companyId,
        AnalyticsFilters $filters,
        int $scale,
    ): array {
        $row = $this->baseQuery($tenantId, $companyId, $filters)
            ->selectRaw('documents.company_id')
            ->selectRaw('CAST(COALESCE(SUM(documents.total), 0) AS TEXT) AS total')
            ->selectRaw('COUNT(documents.id) AS aggregate_count')
            ->selectRaw(
                'CAST(COALESCE(SUM(CASE WHEN COALESCE(expense_metadata.is_paid, false) = false THEN documents.total ELSE 0 END), 0) AS TEXT) AS unpaid_total'
            )
            ->groupBy('documents.company_id')
            ->first();

        return [
            'total' => CurrencyScale::bcformatStrict((string) ($row->total ?? '0'), $scale),
            'count' => (int) ($row->aggregate_count ?? 0),
            'unpaid_total' => CurrencyScale::bcformatStrict((string) ($row->unpaid_total ?? '0'), $scale),
        ];
    }

    /**
     * @param  numeric-string  $grandTotal
     * @return list<ExpenseAnalyticsCategoryData>
     */
    private function byCategory(
        string $tenantId,
        string $companyId,
        AnalyticsFilters $filters,
        string $grandTotal,
        int $scale,
    ): array {
        $rows = $this->withCategories($this->baseQuery($tenantId, $companyId, $filters), $tenantId, $companyId)
            ->selectRaw('expense_categories.id AS category_id')
            ->selectRaw("COALESCE(expense_categories.name, 'Uncategorized') AS category_name")
            ->selectRaw('CAST(COALESCE(SUM(documents.total), 0) AS TEXT) AS total')
            ->groupBy('expense_categories.id', 'expense_categories.name')
            ->orderByRaw('SUM(documents.total) DESC')
            ->get();

        $intermediate = $scale + 4;

        return array_values($rows->map(function (object $row) use ($grandTotal, $intermediate, $scale): ExpenseAnalyticsCategoryData {
            $total = CurrencyScale::bcformatStrict((string) $row->total, $scale);
            $share = bccomp($grandTotal, '0', $scale) === 0
                ? '0.00'
                : CurrencyScale::bcround(
                    bcmul(bcdiv($total, $grandTotal, $intermediate), '100', $intermediate),
                    2,
                );

            return new ExpenseAnalyticsCategoryData(
                category_id: $row->category_id === null ? null : (string) $row->category_id,
                name: (string) $row->category_name,
                total: $total,
                share_percent: $share,
            );
        })->all());
    }

    /** @return list<ExpenseAnalyticsMatrixData> */
    private function matrix(
        string $tenantId,
        string $companyId,
        AnalyticsFilters $filters,
        int $scale,
    ): array {
        $monthExpression = DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(documents.document_date, 'YYYY-MM')"
            : "strftime('%Y-%m', documents.document_date)";

        $rows = $this->withCategories($this->baseQuery($tenantId, $companyId, $filters), $tenantId, $companyId)
            ->selectRaw('expense_categories.id AS category_id')
            ->selectRaw("COALESCE(expense_categories.name, 'Uncategorized') AS category_name")
            ->selectRaw("{$monthExpression} AS month_bucket")
            ->selectRaw('CAST(COALESCE(SUM(documents.total), 0) AS TEXT) AS total')
            ->groupBy('expense_categories.id', 'expense_categories.name', DB::raw($monthExpression))
            ->orderBy('category_name')
            ->orderBy('month_bucket')
            ->get();

        /** @var array<string, array{category_id: string|null, name: string, months: array<string, string>}> $matrix */
        $matrix = [];
        foreach ($rows as $row) {
            $categoryId = $row->category_id === null ? null : (string) $row->category_id;
            $key = $categoryId ?? '__uncategorized__';
            $matrix[$key] ??= [
                'category_id' => $categoryId,
                'name' => (string) $row->category_name,
                'months' => [],
            ];
            $matrix[$key]['months'][(string) $row->month_bucket] = CurrencyScale::bcformatStrict(
                (string) $row->total,
                $scale,
            );
        }

        return array_values(array_map(
            static fn (array $row): ExpenseAnalyticsMatrixData => new ExpenseAnalyticsMatrixData(
                category_id: $row['category_id'],
                name: $row['name'],
                months: $row['months'],
            ),
            $matrix,
        ));
    }

    /** @return list<ExpenseAnalyticsVendorData> */
    private function topVendors(
        string $tenantId,
        string $companyId,
        AnalyticsFilters $filters,
        int $scale,
    ): array {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $partnerText = $pgsql ? 'partners.id::text' : 'CAST(partners.id AS TEXT)';
        $vendorKey = "COALESCE({$partnerText}, expense_metadata.vendor_name)";

        $rows = $this->baseQuery($tenantId, $companyId, $filters)
            ->leftJoin('partners', function (JoinClause $join) use ($tenantId, $companyId): void {
                $join->on('partners.id', '=', 'documents.partner_id')
                    ->where('partners.tenant_id', '=', $tenantId)
                    ->where('partners.company_id', '=', $companyId);
            })
            ->selectRaw("MIN({$partnerText}) AS partner_id")
            ->selectRaw("COALESCE(MAX(partners.name), MAX(expense_metadata.vendor_name), 'Unknown vendor') AS vendor_name")
            ->selectRaw('CAST(COALESCE(SUM(documents.total), 0) AS TEXT) AS total')
            ->groupBy(DB::raw($vendorKey))
            ->orderByRaw('SUM(documents.total) DESC')
            ->get();

        return array_values($rows->map(static fn (object $row): ExpenseAnalyticsVendorData => new ExpenseAnalyticsVendorData(
            partner_id: $row->partner_id === null ? null : (string) $row->partner_id,
            vendor_name: (string) $row->vendor_name,
            total: CurrencyScale::bcformatStrict((string) $row->total, $scale),
        ))->all());
    }

    private function baseQuery(string $tenantId, string $companyId, AnalyticsFilters $filters): Builder
    {
        $dateColumn = DB::connection()->getDriverName() === 'sqlite'
            ? DB::raw('date(documents.document_date)')
            : 'documents.document_date';

        return DB::table('documents')
            ->leftJoin('expense_metadata', 'expense_metadata.document_id', '=', 'documents.id')
            ->where('documents.tenant_id', $tenantId)
            ->where('documents.company_id', $companyId)
            ->where('documents.type', DocumentType::Expense->value)
            ->when(
                $filters->status !== AnalyticsFilters::ALL_STATUSES,
                static fn (Builder $query): Builder => $query->where('documents.status', $filters->status),
            )
            ->whereBetween($dateColumn, [$filters->date_from, $filters->date_to])
            ->whereNull('documents.deleted_at')
            ->when(
                $filters->category_id !== null,
                static fn (Builder $query): Builder => $query->where(
                    'expense_metadata.expense_category_id',
                    $filters->category_id,
                ),
            );
    }

    private function withCategories(Builder $query, string $tenantId, string $companyId): Builder
    {
        return $query->leftJoin('expense_categories', function (JoinClause $join) use ($tenantId, $companyId): void {
            $join->on('expense_categories.id', '=', 'expense_metadata.expense_category_id')
                ->where('expense_categories.tenant_id', '=', $tenantId)
                ->where('expense_categories.company_id', '=', $companyId);
        });
    }

    private function priorPeriod(AnalyticsFilters $filters): AnalyticsFilters
    {
        $from = CarbonImmutable::parse($filters->date_from);
        $to = CarbonImmutable::parse($filters->date_to);
        $days = $from->diffInDays($to) + 1;
        $priorTo = $from->subDay();

        return new AnalyticsFilters(
            date_from: $priorTo->subDays($days - 1)->toDateString(),
            date_to: $priorTo->toDateString(),
            category_id: $filters->category_id,
            status: $filters->status,
        );
    }
}
