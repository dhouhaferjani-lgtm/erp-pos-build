<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\DTOs\Reports\SalesSummaryData;
use App\Modules\Accounting\Application\DTOs\Reports\SalesSummaryDeltaData;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class OwnerSalesSummaryService
{
    private const QTY_SCALE = 4;

    /** High-precision intermediate scale for coercing raw DB strings to numeric-string. */
    private const INTERMEDIATE_SCALE = 10;

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     *
     * @throws AuthorizationException
     */
    public function summary(DateRangeData $range, array $companyIds, array $locationIds): SalesSummaryData
    {
        if ($companyIds === [] || $locationIds === []) {
            return $this->zero('', $this->scaleResolver->getScaleSafe(null, 3));
        }

        $currency = $this->resolveCurrency($companyIds);
        $scale = $this->scaleResolver->getScale($currency);

        $current = $this->aggregate($range, $companyIds, $locationIds);

        $lengthDays = (int) $range->from->startOfDay()->diffInDays($range->to->startOfDay()) + 1;
        $prior = $this->aggregate(
            new DateRangeData(from: $range->from->subDays($lengthDays), to: $range->from->subDay()),
            $companyIds,
            $locationIds,
        );

        $averageBasket = $current['saleCount'] > 0
            ? CurrencyScale::bcround(bcdiv($current['gross'], (string) $current['saleCount'], $scale + 2), $scale)
            : null;

        // Net of returns for BOTH windows — the headline figure (O-28) and its trend
        // badge must come from the same definition. Intermediates at $scale + 1; the
        // single rounding boundary is the bcformatStrict/pct call that consumes them.
        $currentNet = bcsub($current['gross'], $current['returns'], $scale + 1);
        $priorNet = bcsub($prior['gross'], $prior['returns'], $scale + 1);

        return new SalesSummaryData(
            currencyCode: $currency,
            grossSales: CurrencyScale::bcformatStrict($current['gross'], $scale),
            returnsAmount: CurrencyScale::bcformatStrict($current['returns'], $scale),
            netSales: CurrencyScale::bcformatStrict($currentNet, $scale),
            salesCount: $current['saleCount'],
            returnsCount: $current['returnCount'],
            itemsSold: CurrencyScale::bcformatStrict($current['items'], self::QTY_SCALE),
            averageBasket: $averageBasket,
            delta: new SalesSummaryDeltaData(
                grossSalesAbs: CurrencyScale::bcformatStrict(bcsub($current['gross'], $prior['gross'], $scale + 1), $scale),
                grossSalesPct: $this->pct($current['gross'], $prior['gross']),
                netSalesAbs: CurrencyScale::bcformatStrict(bcsub($currentNet, $priorNet, $scale + 1), $scale),
                netSalesPct: $this->pct($currentNet, $priorNet),
                salesCountAbs: $current['saleCount'] - $prior['saleCount'],
                salesCountPct: $this->pct((string) $current['saleCount'], (string) $prior['saleCount']),
            ),
        );
    }

    /**
     * @param  list<string>  $companyIds
     *
     * @throws AuthorizationException
     */
    private function resolveCurrency(array $companyIds): string
    {
        $currencies = DB::table('companies')->whereIn('id', $companyIds)->distinct()->pluck('currency');

        if ($currencies->count() > 1) {
            throw new AuthorizationException('Owner reporting cannot aggregate across companies with different currencies.');
        }

        return (string) ($currencies->first() ?? 'EUR');
    }

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     * @return array{gross:numeric-string, returns:numeric-string, saleCount:int, returnCount:int, items:numeric-string}
     */
    private function aggregate(DateRangeData $range, array $companyIds, array $locationIds): array
    {
        $sale = ReceiptType::Sale->value;
        $return = ReceiptType::Return->value;

        $receipts = DB::table('pos_receipts')
            ->whereIn('company_id', $companyIds)
            ->whereIn('location_id', $locationIds)
            ->where('is_voided', false)
            ->where('training_flag', false)
            ->whereBetween('posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->selectRaw('COALESCE(SUM(CASE WHEN receipt_type = ? THEN total ELSE 0 END), 0) as gross', [$sale])
            // ABS goes INSIDE the SUM, per row. Legacy returns stored a NEGATIVE
            // total and v4 refunds store a POSITIVE one (v3-refund-chain
            // spec §7.7), so an outer ABS(SUM(...)) lets the two eras CANCEL
            // inside the sum — a window spanning the cutover would report zero
            // returns and net nothing out of sales. Ticket
            // 2026-08-01-positive-refund-total-consumers.
            ->selectRaw('COALESCE(SUM(CASE WHEN receipt_type = ? THEN ABS(total) ELSE 0 END), 0) as returns', [$return])
            ->selectRaw('COUNT(CASE WHEN receipt_type = ? THEN 1 END) as sale_count', [$sale])
            ->selectRaw('COUNT(CASE WHEN receipt_type = ? THEN 1 END) as return_count', [$return])
            ->first();

        $items = DB::table('pos_receipt_lines')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
            ->whereIn('pos_receipts.company_id', $companyIds)
            ->whereIn('pos_receipts.location_id', $locationIds)
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.training_flag', false)
            ->where('pos_receipts.receipt_type', $sale)
            ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.quantity), 0) as items')
            ->first();

        // Coerce DB-returned mixed values to numeric-string via CurrencyScale::bcformatStrict
        // (validates numeric + uses INTERMEDIATE_SCALE class constant, not a bare literal).
        $toNumericString = static fn (mixed $v): string => CurrencyScale::bcformatStrict((string) ($v ?? '0'), self::INTERMEDIATE_SCALE);

        return [
            'gross' => $toNumericString($receipts->gross ?? null),
            'returns' => $toNumericString($receipts->returns ?? null),
            'saleCount' => (int) ($receipts->sale_count ?? 0),
            'returnCount' => (int) ($receipts->return_count ?? 0),
            'items' => $toNumericString($items->items ?? null),
        ];
    }

    private function pct(string $current, string $prior): ?string
    {
        // Coerce plain string to numeric-string via CurrencyScale::bcformatStrict (validates numeric
        // + uses INTERMEDIATE_SCALE class constant); inputs are DB decimal strings or int-cast counts.
        $cur = CurrencyScale::bcformatStrict($current, self::INTERMEDIATE_SCALE);
        $pre = CurrencyScale::bcformatStrict($prior, self::INTERMEDIATE_SCALE);

        // precision-ok: percentage comparisons use a fixed guard scale (6 dp) — not currency-scaled.
        if (bccomp($pre, '0', 6) === 0) {
            return null;
        }

        // precision-ok: percentage arithmetic uses fixed intermediate (8 dp) and display (6 dp) scales — not currency-scaled.
        $raw = bcmul(bcdiv(bcsub($cur, $pre, 8), $pre, 8), '100', 6);

        // Round (not truncate) at the boundary — percent is NOT currency-scaled.
        return CurrencyScale::bcround($raw, 2);
    }

    private function zero(string $currency, int $scale): SalesSummaryData
    {
        $zero = CurrencyScale::bcformatStrict('0', $scale);

        return new SalesSummaryData(
            currencyCode: $currency,
            grossSales: $zero,
            returnsAmount: $zero,
            netSales: $zero,
            salesCount: 0,
            returnsCount: 0,
            itemsSold: CurrencyScale::bcformatStrict('0', self::QTY_SCALE),
            averageBasket: null,
            delta: new SalesSummaryDeltaData($zero, null, $zero, null, 0, null),
        );
    }
}
