<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;

/**
 * MarginService - Handles margin calculations and pricing validation
 */
class MarginService
{
    /**
     * Margin indicator levels
     */
    public const LEVEL_GREEN = 'green';   // Above target

    public const LEVEL_YELLOW = 'yellow'; // Below target, above minimum

    public const LEVEL_ORANGE = 'orange'; // Below minimum, above cost

    public const LEVEL_RED = 'red';       // Below cost (loss)

    /**
     * Get effective margins for a product (with inheritance)
     *
     * @return array{target_margin: float, minimum_margin: float, source: string}
     */
    public function getEffectiveMargins(Product $product): array
    {
        $company = $product->company;

        // For now, skip category since it doesn't exist
        // Will implement: product → category → company when categories are added
        $targetMargin = (float) ($product->target_margin_override
            ?? $company->default_target_margin ?? 30.0);

        $minimumMargin = (float) ($product->minimum_margin_override
            ?? $company->default_minimum_margin ?? 15.0);

        return [
            'target_margin' => $targetMargin,
            'minimum_margin' => $minimumMargin,
            'source' => $this->getMarginSource($product),
        ];
    }

    /**
     * Calculate suggested sell price based on cost and target margin
     */
    public function getSuggestedPrice(Product $product): float
    {
        $cost = (float) ($product->cost_price ?? 0);
        $margins = $this->getEffectiveMargins($product);

        if ($cost <= 0) {
            return (float) ($product->sale_price ?? 0);
        }

        return round($cost * (1 + $margins['target_margin'] / 100), 2);
    }

    /**
     * Update product sale price based on current cost and target margin.
     *
     * This method is called automatically after WAC updates.
     * Only updates if product uses auto-pricing (no manual sale_price override).
     *
     * @return bool True if sale price was updated
     */
    public function updateSalePrice(Product $product): bool
    {
        $cost = (float) ($product->cost_price ?? 0);

        // Don't update if no cost
        if ($cost <= 0) {
            return false;
        }

        // Get target margin (product > company)
        $margins = $this->getEffectiveMargins($product);
        $targetMargin = $margins['target_margin'];

        // Calculate new sale price
        $newSalePrice = round($cost * (1 + $targetMargin / 100), 2);

        // Only update if different (avoid unnecessary writes)
        $currentSalePrice = (float) ($product->sale_price ?? 0);
        if (abs($newSalePrice - $currentSalePrice) < 0.01) {
            return false;
        }

        // Update sale price
        $product->sale_price = (string) $newSalePrice;
        $product->save();

        return true;
    }

    /**
     * Calculate actual margin for a given sell price
     */
    public function calculateMargin(float $cost, float $sellPrice): ?float
    {
        if ($cost <= 0) {
            return null;
        }

        return round((($sellPrice - $cost) / $cost) * 100, 2);
    }

    /**
     * Get margin indicator level for a sell price
     *
     * @return array{level: string, message: string, actual_margin: float|null, target_margin?: float, minimum_margin?: float, loss_amount?: float}
     */
    public function getMarginLevel(Product $product, float $sellPrice): array
    {
        $cost = (float) ($product->cost_price ?? 0);
        $margins = $this->getEffectiveMargins($product);
        $actualMargin = $this->calculateMargin($cost, $sellPrice);

        if ($cost <= 0) {
            return [
                'level' => self::LEVEL_GREEN,
                'message' => 'No cost data',
                'actual_margin' => null,
            ];
        }

        if ($sellPrice < $cost) {
            return [
                'level' => self::LEVEL_RED,
                'message' => 'Below cost - LOSS',
                'actual_margin' => $actualMargin,
                'loss_amount' => round($cost - $sellPrice, 2),
            ];
        }

        if ($actualMargin < $margins['minimum_margin']) {
            return [
                'level' => self::LEVEL_ORANGE,
                'message' => 'Below minimum margin',
                'actual_margin' => $actualMargin,
                'minimum_margin' => $margins['minimum_margin'],
            ];
        }

        if ($actualMargin < $margins['target_margin']) {
            return [
                'level' => self::LEVEL_YELLOW,
                'message' => 'Below target margin',
                'actual_margin' => $actualMargin,
                'target_margin' => $margins['target_margin'],
            ];
        }

        return [
            'level' => self::LEVEL_GREEN,
            'message' => 'Above target margin',
            'actual_margin' => $actualMargin,
        ];
    }

    /**
     * Check if user can sell at this price
     *
     * @return array{allowed: bool, reason: string|null, requires_permission?: string, margin_level?: array<string, mixed>}
     */
    public function canSellAtPrice(
        Product $product,
        float $sellPrice,
        User $user
    ): array {
        $marginLevel = $this->getMarginLevel($product, $sellPrice);
        $company = $product->company;

        // Below cost check
        if ($marginLevel['level'] === self::LEVEL_RED) {
            if (! $company->allow_below_cost_sales) {
                return [
                    'allowed' => false,
                    'reason' => 'Sales below cost are not allowed',
                    'requires_permission' => 'sell_below_cost',
                ];
            }

            if (! $user->can('pricing.sell_below_cost')) {
                return [
                    'allowed' => false,
                    'reason' => 'You do not have permission to sell below cost',
                    'requires_permission' => 'pricing.sell_below_cost',
                ];
            }
        }

        // Below minimum margin check
        if ($marginLevel['level'] === self::LEVEL_ORANGE) {
            if (! $user->can('pricing.sell_below_minimum_margin')) {
                return [
                    'allowed' => false,
                    'reason' => 'You do not have permission to sell below minimum margin',
                    'requires_permission' => 'pricing.sell_below_minimum_margin',
                ];
            }
        }

        // Below target margin check (warning only, generally allowed)
        if ($marginLevel['level'] === self::LEVEL_YELLOW) {
            if (! $user->can('pricing.sell_below_target_margin')) {
                return [
                    'allowed' => false,
                    'reason' => 'You do not have permission to sell below target margin',
                    'requires_permission' => 'pricing.sell_below_target_margin',
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'margin_level' => $marginLevel,
        ];
    }

    /**
     * Determine the source of margin configuration
     */
    private function getMarginSource(Product $product): string
    {
        if ($product->target_margin_override !== null) {
            return 'product';
        }

        // Skip category check since it doesn't exist yet
        // When categories are added:
        // if ($product->category?->target_margin_override !== null) {
        //     return 'category';
        // }

        return 'company';
    }
}
