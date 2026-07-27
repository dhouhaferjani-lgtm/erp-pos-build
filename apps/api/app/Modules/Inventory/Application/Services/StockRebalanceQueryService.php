<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Shared\Domain\QuantityScale;
use Illuminate\Support\Facades\DB;

final class StockRebalanceQueryService
{
    private const SCALE = QuantityScale::SCALE;

    /**
     * @param  list<string>  $locationIds
     * @return array{data: list<array<string, mixed>>}
     */
    public function rebalance(string $tenantId, string $companyId, array $locationIds): array
    {
        if ($locationIds === []) {
            return ['data' => []];
        }

        $products = DB::table('products')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->where('products.tenant_id', $tenantId)
            ->where('products.company_id', $companyId)
            ->whereNull('products.deleted_at')
            ->orderBy('products.name')->orderBy('products.id')
            ->get(['products.id', 'products.name', 'products.sku', 'units.decimal_places']);
        $productIds = $products->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        if ($productIds === []) {
            return ['data' => []];
        }

        $stock = DB::table('stock_levels')
            ->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->whereIn('product_id', $productIds)->whereIn('location_id', $locationIds)
            ->get(['product_id', 'variant_id', 'location_id', 'quantity', 'reserved', 'min_quantity', 'max_quantity']);
        $groups = [];
        foreach ($stock as $row) {
            $key = (string) $row->product_id.'|'.((string) ($row->variant_id ?? 'base'));
            $groups[$key]['product_id'] = (string) $row->product_id;
            $groups[$key]['variant_id'] = $row->variant_id !== null ? (string) $row->variant_id : null;
            $groups[$key]['cells'][(string) $row->location_id] = [
                'available' => $this->available((string) $row->quantity, (string) $row->reserved),
                'min_quantity' => $row->min_quantity !== null ? (string) $row->min_quantity : null,
                'max_quantity' => $row->max_quantity !== null ? (string) $row->max_quantity : null,
            ];
        }
        $names = [];
        foreach ($products as $product) {
            $names[(string) $product->id] = [
                'name' => (string) $product->name,
                'sku' => (string) $product->sku,
                'quantity_decimals' => $product->decimal_places !== null
                    ? (int) $product->decimal_places
                    : QuantityScale::SCALE,
            ];
        }
        $result = [];
        foreach ($groups as $group) {
            $cells = $group['cells'];
            $deficits = [];
            $surpluses = [];
            $hasThreshold = false;
            foreach ($cells as $locationId => $cell) {
                if ($cell['min_quantity'] !== null || $cell['max_quantity'] !== null) {
                    $hasThreshold = true;
                }
                if ($cell['min_quantity'] !== null && $this->compare($cell['available'], $cell['min_quantity']) < 0) {
                    $deficits[] = ['location_id' => $locationId, 'available' => $cell['available'], 'min_quantity' => $cell['min_quantity']];
                }
                if ($cell['max_quantity'] !== null && $this->compare($cell['available'], $cell['max_quantity']) > 0) {
                    $surpluses[] = ['location_id' => $locationId, 'available' => $cell['available'], 'max_quantity' => $cell['max_quantity'], 'excess' => $this->subtract($cell['available'], $cell['max_quantity'])];
                }
            }
            if (! $hasThreshold) {
                $donor = null;
                $receiver = null;
                foreach ($cells as $locationId => $cell) {
                    if ($donor === null || $this->compare($cell['available'], $donor['available']) > 0) {
                        $donor = ['location_id' => $locationId, 'available' => $cell['available'], 'max_quantity' => null, 'excess' => $cell['available']];
                    }
                    if ($this->compare($cell['available'], '0') <= 0 && ($receiver === null || $this->compare($cell['available'], $receiver['available']) < 0)) {
                        $receiver = ['location_id' => $locationId, 'available' => $cell['available'], 'min_quantity' => null];
                    }
                }
                $surpluses = $this->compare($donor['available'], '0') > 0 ? [$donor] : [];
                $deficits = $receiver !== null ? [$receiver] : [];
            }
            if ($deficits === [] || $surpluses === []) {
                continue;
            }
            usort($deficits, fn (array $a, array $b): int => $this->compare($a['available'], $b['available']));
            // Rank by deficit depth (threshold minus available), not merely by
            // the lowest available balance. A location at 1/10 is more urgent
            // than one at 0/1, even though the latter has the lower balance.
            $severity = '0.0000';
            foreach ($deficits as $deficit) {
                $threshold = $deficit['min_quantity'] ?? '0.0000';
                $depth = $this->subtract($threshold, $deficit['available']);
                if ($this->compare($depth, $severity) > 0) {
                    $severity = $depth;
                }
            }
            $productId = $group['product_id'];
            $result[] = ['product_id' => $productId, 'variant_id' => $group['variant_id'], 'name' => $names[$productId]['name'], 'sku' => $names[$productId]['sku'], 'quantity_decimals' => $names[$productId]['quantity_decimals'], 'deficits' => $deficits, 'surpluses' => $surpluses, '_severity' => $severity];
        }
        usort($result, fn (array $a, array $b): int => $this->compare($b['_severity'], $a['_severity']));
        foreach ($result as &$row) {
            unset($row['_severity']);
        }

        return ['data' => $result];
    }

    /** @return numeric-string */
    private function available(string $quantity, string $reserved): string
    {
        /** @var numeric-string $quantity */
        /** @var numeric-string $reserved */
        return bcsub($quantity, $reserved, self::SCALE);
    }

    private function compare(string $left, string $right): int
    {
        /** @var numeric-string $left */
        /** @var numeric-string $right */
        return bccomp($left, $right, self::SCALE);
    }

    /** @return numeric-string */
    private function subtract(string $left, string $right): string
    {
        /** @var numeric-string $left */
        /** @var numeric-string $right */
        return bcsub($left, $right, self::SCALE);
    }
}
