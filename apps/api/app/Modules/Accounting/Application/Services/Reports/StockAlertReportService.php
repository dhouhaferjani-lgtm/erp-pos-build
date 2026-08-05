<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\StockAlertData;
use App\Shared\Domain\QuantityScale;
use Illuminate\Support\Facades\DB;

/**
 * DELIBERATELY has no `CurrencyScaleResolverInterface` and no constructor,
 * unlike its three siblings in this directory.
 *
 * This report emits only QUANTITIES (rendered at the product unit's
 * `decimal_places`) and a severity label — not a single money figure — so there
 * is no currency scale to resolve. Injecting the resolver here would add a
 * dependency nothing uses. Stated explicitly because the L4 handoff summary
 * claimed all four services inject it; the code is right and the claim was
 * wrong.
 */
final class StockAlertReportService
{
    use FormatsReportNumbers;

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     * @return list<StockAlertData>
     */
    public function lowStockAcrossLocations(array $companyIds, array $locationIds, int $thresholdPct): array
    {
        if ($companyIds === [] || $locationIds === []) {
            return [];
        }

        // Quantity predicate — kept in bcmath so the threshold ratio is an exact
        // decimal string rather than a float the driver has to re-parse.
        $thresholdRatio = bcdiv((string) $thresholdPct, '100', QuantityScale::SCALE);

        $rows = DB::table('stock_levels')
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->whereIn('stock_levels.company_id', $companyIds)
            ->whereIn('stock_levels.location_id', $locationIds)
            ->whereNotNull('stock_levels.min_quantity')
            ->whereRaw('stock_levels.quantity <= (stock_levels.min_quantity * ?)', [$thresholdRatio])
            ->selectRaw('products.id as product_id')
            ->selectRaw('products.name as product_name')
            ->selectRaw('locations.id as location_id')
            ->selectRaw('locations.name as location_name')
            ->selectRaw('stock_levels.quantity')
            ->selectRaw('stock_levels.min_quantity')
            ->selectRaw('COALESCE(units.decimal_places, 4) as quantity_decimals')
            ->orderBy('products.name')
            ->get();

        return array_values($rows->map(fn (object $row): StockAlertData => new StockAlertData(
            product_id: (string) $row->product_id,
            product_name: (string) $row->product_name,
            location_id: (string) $row->location_id,
            location_name: (string) $row->location_name,
            // These are QUANTITIES, not money: they follow the product unit's
            // `decimal_places` (also emitted, so the client renders the same
            // precision) — never the currency scale. (W-7 F-2.)
            quantity: $this->quantityString($row->quantity, (int) $row->quantity_decimals),
            min_quantity: $this->quantityString($row->min_quantity, (int) $row->quantity_decimals),
            quantity_decimals: (int) $row->quantity_decimals,
            threshold_pct: $thresholdPct,
            severity: $this->severity(
                $this->numericString($row->quantity),
                $this->numericString($row->min_quantity),
            ),
        ))->all());
    }

    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string  $minimum
     */
    private function severity(string $quantity, string $minimum): string
    {
        if (bccomp($quantity, '0', QuantityScale::SCALE) <= 0) {
            return 'out_of_stock';
        }

        $half = bcmul($minimum, '0.5', QuantityScale::SCALE);

        if (bccomp($minimum, '0', QuantityScale::SCALE) > 0 && bccomp($quantity, $half, QuantityScale::SCALE) <= 0) {
            return 'critical';
        }

        return 'warning';
    }
}
