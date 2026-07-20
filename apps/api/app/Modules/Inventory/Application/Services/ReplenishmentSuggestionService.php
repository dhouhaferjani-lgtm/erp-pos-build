<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\StockLevel;
use App\Shared\Domain\QuantityScale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Inventory-owned read seam for POS replenishment quantity suggestions.
 */
final class ReplenishmentSuggestionService
{
    private const FLOOR_QUANTITY = '1.0000';

    /**
     * @param  list<array{product_id: string, variant_id: string|null}>  $items
     * @return array<string, numeric-string>
     */
    public function suggestionsForLocation(
        string $tenantId,
        string $companyId,
        string $locationId,
        array $items,
    ): array {
        /** @var array<string, numeric-string> $suggestions */
        $suggestions = [];
        foreach ($items as $item) {
            $suggestions[self::grainKey($item['product_id'], $item['variant_id'])] = self::FLOOR_QUANTITY;
        }

        if ($suggestions === []) {
            return [];
        }

        $levels = StockLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->where(function (Builder $query) use ($items): void {
                foreach ($items as $item) {
                    $query->orWhere(function (Builder $grain) use ($item): void {
                        $grain->where('product_id', $item['product_id']);
                        $item['variant_id'] === null
                            ? $grain->whereNull('variant_id')
                            : $grain->where('variant_id', $item['variant_id']);
                    });
                }
            })
            ->get([
                'product_id',
                'variant_id',
                'quantity',
                'reserved',
                'min_quantity',
                'max_quantity',
            ]);

        foreach ($levels as $level) {
            if ($level->max_quantity !== null) {
                $target = $level->max_quantity;
            } elseif ($level->min_quantity !== null) {
                $target = $level->min_quantity;
            } else {
                continue;
            }

            $targetQuantity = QuantityScale::round(
                $target,
                QuantityScale::SCALE,
                QuantityScale::HALF_UP,
            );
            /** @var numeric-string $difference */
            $difference = bcsub($targetQuantity, $level->getAvailableQuantity(), QuantityScale::SCALE);
            $suggestions[self::grainKey($level->product_id, $level->variant_id)] =
                bccomp($difference, '0', QuantityScale::SCALE) > 0
                    ? $difference
                    : self::FLOOR_QUANTITY;
        }

        // Round-once-at-boundary: internal math stayed scale-4; format each
        // suggestion at its product unit's display precision (pieces => whole
        // numbers, kg => 3dp). One batched lookup — no cross-module model import.
        $productIds = array_values(array_unique(array_column($items, 'product_id')));
        $unitMeta = DB::table('products')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->whereIn('products.id', $productIds)
            ->get(['products.id', 'units.decimal_places', 'units.rounding_method'])
            ->keyBy('id');

        foreach ($suggestions as $key => $value) {
            [$productId] = explode('|', $key, 2);
            $meta = $unitMeta->get($productId);
            $decimalPlaces = $meta?->decimal_places !== null ? (int) $meta->decimal_places : null;
            $roundingMethod = $meta?->rounding_method !== null ? (string) $meta->rounding_method : null;
            $suggestions[$key] = QuantityScale::formatForUnit($value, $decimalPlaces, $roundingMethod);
        }

        return $suggestions;
    }

    public static function grainKey(string $productId, ?string $variantId): string
    {
        return $productId.'|'.($variantId ?? '');
    }
}
