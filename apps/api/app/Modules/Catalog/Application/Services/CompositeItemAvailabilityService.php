<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Catalog\Domain\Enums\ComponentType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Shared\Domain\CurrencyScale;

final class CompositeItemAvailabilityService
{
    private const int MAX_RECURSION_DEPTH = 10;

    /**
     * Returns the maximum quantity of the composite item that can be produced
     * from available stock at a given location.
     *
     * Formula: min(available_stock / recipe_quantity) across all required leaf components.
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

        $recipe->loadMissing('lines.product', 'lines.compositeItemComponent.activeRecipe.lines');

        // Collect all leaf-level product requirements (flattened)
        /** @var array<string, array{product_id: string, product_name: string, required_quantity: string}> $leafProducts */
        $leafProducts = [];
        $this->collectLeafProducts($recipe, '1', $leafProducts);

        $minProducible = PHP_INT_MAX;
        $limitingComponent = null;
        $components = [];

        foreach ($leafProducts as $productId => $info) {
            /** @var numeric-string $requiredQty */
            $requiredQty = $info['required_quantity'];
            if (bccomp($requiredQty, '0', 4) <= 0) {
                continue;
            }

            $stockLevel = StockLevel::where('product_id', $productId)
                ->where('location_id', $locationId)
                ->first();

            $availableQty = $stockLevel !== null ? CurrencyScale::bcformat($stockLevel->quantity, 4) : '0';
            /** @phpstan-var numeric-string $availableQty */
            /** @phpstan-var numeric-string $requiredQty */
            $maxProduces = (int) bcdiv($availableQty, $requiredQty, 0);

            if ($maxProduces < $minProducible) {
                $minProducible = $maxProduces;
                $limitingComponent = $info['product_name'];
            }

            $components[] = [
                'product_id' => $productId,
                'product_name' => $info['product_name'],
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

    /**
     * Recursively collect leaf-level product requirements from a recipe.
     * Aggregates quantities for products that appear in multiple sub-recipes.
     *
     * @param  array<string, array{product_id: string, product_name: string, required_quantity: string}>  $leafProducts
     */
    private function collectLeafProducts(Recipe $recipe, string $multiplier, array &$leafProducts, int $depth = 0): void
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return;
        }

        /** @var RecipeLine $line */
        foreach ($recipe->lines as $line) {
            if ($line->is_optional) {
                continue;
            }

            /** @phpstan-ignore argument.type */
            $lineQty = bcmul(CurrencyScale::bcformat($line->quantity, 4), $multiplier, 4);

            if ($line->component_type === ComponentType::CompositeItem) {
                /** @var CompositeItem|null $compositeItem */
                $compositeItem = $line->compositeItemComponent;
                if ($compositeItem === null) {
                    continue;
                }

                $subRecipe = $compositeItem->activeRecipe;
                if ($subRecipe === null) {
                    continue;
                }

                $subRecipe->loadMissing('lines.product', 'lines.compositeItemComponent.activeRecipe.lines');
                $this->collectLeafProducts($subRecipe, $lineQty, $leafProducts, $depth + 1);
            } else {
                $productId = $line->component_id;
                if (isset($leafProducts[$productId])) {
                    /** @var numeric-string $existingQty */
                    $existingQty = $leafProducts[$productId]['required_quantity'];
                    $leafProducts[$productId]['required_quantity'] = bcadd(
                        $existingQty,
                        $lineQty,
                        4
                    );
                } else {
                    $leafProducts[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $line->product->name ?? 'Unknown',
                        'required_quantity' => $lineQty,
                    ];
                }
            }
        }
    }
}
