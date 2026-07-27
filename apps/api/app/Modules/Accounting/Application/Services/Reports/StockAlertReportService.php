<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\StockAlertData;
use Illuminate\Support\Facades\DB;

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

        $thresholdRatio = $thresholdPct / 100;

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
            quantity: $this->decimalString($row->quantity),
            min_quantity: $this->decimalString($row->min_quantity),
            quantity_decimals: (int) $row->quantity_decimals,
            threshold_pct: $thresholdPct,
            severity: $this->severity((float) $row->quantity, (float) $row->min_quantity),
        ))->all());
    }

    private function severity(float $quantity, float $minimum): string
    {
        if ($quantity <= 0) {
            return 'out_of_stock';
        }

        if ($minimum > 0 && $quantity <= ($minimum * 0.5)) {
            return 'critical';
        }

        return 'warning';
    }
}
