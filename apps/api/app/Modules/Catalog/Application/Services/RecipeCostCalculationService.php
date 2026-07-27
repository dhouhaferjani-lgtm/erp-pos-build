<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\DTOs\RecipeCostData;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Catalog\Domain\Enums\ComponentType;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\QuantityScale;

final class RecipeCostCalculationService
{
    private const int MAX_RECURSION_DEPTH = 10;

    /**
     * Calculate recipe cost by iterating lines, fetching product cost_price, applying wastage.
     * Recursively resolves CompositeItem components.
     */
    public function calculate(Recipe $recipe): RecipeCostData
    {
        $recipe->loadMissing(
            'lines.unit',
            'lines.product.unitOfMeasure',
            'lines.compositeItemComponent.activeRecipe.lines'
        );

        $totalCost = '0';
        $costLines = [];

        /** @var RecipeLine $line */
        foreach ($recipe->lines as $line) {
            $unitCost = $this->resolveComponentUnitCost($line);

            // Apply wastage: effective_qty = qty * (1 + wastage_percent / 100)
            $wastageStr = CurrencyScale::bcformat($line->wastage_percent, 6);
            $quantityStr = CurrencyScale::bcformat($line->quantity, 4);

            $wastageMultiplier = bcadd('1', bcdiv($wastageStr, '100', 6), 6);
            $effectiveQuantity = bcmul($quantityStr, $wastageMultiplier, 4);

            /** @var numeric-string $unitCostNumeric */
            $unitCostNumeric = $unitCost;
            $lineCost = bcmul($effectiveQuantity, $unitCostNumeric, 4);
            $totalCost = bcadd($totalCost, $lineCost, 4);

            // Update the line's cached cost values
            $line->update([
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
            ]);

            $componentName = $this->resolveComponentName($line);

            $costLines[] = [
                'component_name' => $componentName,
                'quantity' => (string) $line->quantity,
                'quantity_decimals' => $this->resolveQuantityDecimals($line),
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
                'percent_of_total' => '0', // calculated below
            ];
        }

        // Calculate percentage of total for each line
        if (bccomp($totalCost, '0', 4) > 0) {
            foreach ($costLines as &$costLine) {
                $costLine['percent_of_total'] = bcmul(
                    bcdiv($costLine['line_cost'], $totalCost, 6),
                    '100',
                    2
                );
            }
            unset($costLine);
        }

        // Update recipe's calculated cost
        $recipe->update(['calculated_cost' => $totalCost]);

        return new RecipeCostData(
            total_cost: $totalCost,
            lines: $costLines,
        );
    }

    /**
     * Resolve the unit cost of a recipe line component.
     * For products: use cost_price.
     * For composite items: recursively calculate their recipe cost.
     */
    private function resolveComponentUnitCost(RecipeLine $line, int $depth = 0): string
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return '0.0000';
        }

        if ($line->component_type === ComponentType::CompositeItem) {
            /** @var CompositeItem|null $compositeItem */
            $compositeItem = $line->compositeItemComponent;
            if ($compositeItem === null) {
                return '0.0000';
            }

            $subRecipe = $compositeItem->activeRecipe;
            if ($subRecipe === null) {
                return '0.0000';
            }

            $subRecipe->loadMissing('lines.product', 'lines.compositeItemComponent.activeRecipe.lines');

            return $this->calculateRecipeCostRecursive($subRecipe, $depth + 1);
        }

        /** @var Product|null $product */
        $product = $line->product;

        return $product !== null ? CurrencyScale::bcformat($product->cost_price ?? 0, 4) : '0.0000';
    }

    /**
     * Calculate total cost of a recipe recursively (without persisting).
     */
    private function calculateRecipeCostRecursive(Recipe $recipe, int $depth): string
    {
        $totalCost = '0';

        /** @var RecipeLine $line */
        foreach ($recipe->lines as $line) {
            $unitCost = $this->resolveComponentUnitCost($line, $depth);

            $wastageStr = CurrencyScale::bcformat($line->wastage_percent, 6);
            $quantityStr = CurrencyScale::bcformat($line->quantity, 4);

            $wastageMultiplier = bcadd('1', bcdiv($wastageStr, '100', 6), 6);
            $effectiveQuantity = bcmul($quantityStr, $wastageMultiplier, 4);

            /** @var numeric-string $unitCostNumeric */
            $unitCostNumeric = $unitCost;
            $lineCost = bcmul($effectiveQuantity, $unitCostNumeric, 4);
            $totalCost = bcadd($totalCost, $lineCost, 4);
        }

        return $totalCost;
    }

    private function resolveComponentName(RecipeLine $line): string
    {
        if ($line->component_type === ComponentType::CompositeItem) {
            return $line->compositeItemComponent->name ?? 'Unknown';
        }

        return $line->product->name ?? 'Unknown';
    }

    private function resolveQuantityDecimals(RecipeLine $line): int
    {
        if ($line->unit !== null) {
            return $line->unit->decimal_places;
        }

        $product = $line->product;
        if ($product === null || $product->unitOfMeasure === null) {
            return QuantityScale::SCALE;
        }

        return $product->unitOfMeasure->decimal_places;
    }
}
