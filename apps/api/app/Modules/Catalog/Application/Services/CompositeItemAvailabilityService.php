<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Inventory\Domain\StockLevel;

final class CompositeItemAvailabilityService
{
    /**
     * Returns the maximum quantity of the composite item that can be produced
     * from available stock at a given location.
     *
     * Formula: min(available_stock / recipe_quantity) across all required components.
     *
     * @return array{available_quantity: int, limiting_component: string|null, components: array<int, array{product_id: string, product_name: string, required_quantity: string, available_quantity: string, max_produces: int}>}
     */
    public function checkAvailability(CompositeItem $item, string $locationId): array
    {
        $recipe = $item->activeRecipe;
        if ($recipe === null) {
            return [
                'available_quantity' => 0,
                'limiting_component' => null,
                'components' => [],
            ];
        }

        $recipe->loadMissing('lines.component');

        $minProducible = PHP_INT_MAX;
        $limitingComponent = null;
        $components = [];

        /** @var RecipeLine $line */
        foreach ($recipe->lines as $line) {
            if ($line->is_optional) {
                continue;
            }

            $requiredQty = number_format((float) $line->quantity, 4, '.', '');
            /** @phpstan-var numeric-string $requiredQty */
            if (bccomp($requiredQty, '0', 4) <= 0) {
                continue;
            }

            $stockLevel = StockLevel::where('product_id', $line->component_id)
                ->where('location_id', $locationId)
                ->first();

            $availableQty = $stockLevel !== null ? number_format((float) $stockLevel->quantity, 4, '.', '') : '0';
            /** @phpstan-var numeric-string $availableQty */
            $maxProduces = (int) bcdiv($availableQty, $requiredQty, 0);

            if ($maxProduces < $minProducible) {
                $minProducible = $maxProduces;
                $limitingComponent = $line->component->name ?? null;
            }

            $components[] = [
                'product_id' => $line->component_id,
                'product_name' => $line->component->name ?? 'Unknown',
                'required_quantity' => $requiredQty,
                'available_quantity' => $availableQty,
                'max_produces' => $maxProduces,
            ];
        }

        return [
            'available_quantity' => $minProducible === PHP_INT_MAX ? 0 : $minProducible,
            'limiting_component' => $limitingComponent,
            'components' => $components,
        ];
    }
}
