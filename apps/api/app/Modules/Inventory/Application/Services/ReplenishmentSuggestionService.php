<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\StockLevel;
use App\Shared\Domain\QuantityScale;
use Illuminate\Database\Eloquent\Builder;

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

        return $suggestions;
    }

    public static function grainKey(string $productId, ?string $variantId): string
    {
        return $productId.'|'.($variantId ?? '');
    }
}
