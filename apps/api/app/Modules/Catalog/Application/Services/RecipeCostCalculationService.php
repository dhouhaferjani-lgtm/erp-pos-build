<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\DTOs\RecipeCostData;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Product\Domain\Product;

final class RecipeCostCalculationService
{
    /**
     * Calculate recipe cost by iterating lines, fetching product cost_price, applying wastage.
     */
    public function calculate(Recipe $recipe): RecipeCostData
    {
        $recipe->loadMissing('lines.component');

        $totalCost = '0';
        $costLines = [];

        /** @var RecipeLine $line */
        foreach ($recipe->lines as $line) {
            /** @var Product|null $product */
            $product = $line->component;

            $unitCost = $product !== null ? number_format((float) ($product->cost_price ?? 0), 4, '.', '') : '0.0000';

            // Apply wastage: effective_qty = qty * (1 + wastage_percent / 100)
            $wastageStr = number_format((float) $line->wastage_percent, 6, '.', '');
            $quantityStr = number_format((float) $line->quantity, 4, '.', '');

            $wastageMultiplier = bcadd('1', bcdiv($wastageStr, '100', 6), 6);
            $effectiveQuantity = bcmul($quantityStr, $wastageMultiplier, 4);

            $lineCost = bcmul($effectiveQuantity, $unitCost, 4);
            $totalCost = bcadd($totalCost, $lineCost, 4);

            // Update the line's cached cost values
            $line->update([
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
            ]);

            $costLines[] = [
                'component_name' => $product->name ?? 'Unknown',
                'quantity' => (string) $line->quantity,
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
}
