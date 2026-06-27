<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;

/**
 * MarginService - Handles margin calculations and pricing validation.
 *
 * All monetary arithmetic uses bcmath at (scale + 2) intermediate precision and
 * rounds once at the boundary via CurrencyScale::bcformat(). This eliminates the
 * IEEE-754 float drift that could flip a sell price across a discount-permission
 * threshold (e.g. a price exactly at minimum margin being mis-classified ORANGE).
 *
 * Effective margins are resolved via {@see MarginResolver} (3-level hierarchy:
 * product → category chain → company → hard-coded fallback).
 *
 * Scale resolution is currency-aware and queue-safe: every pricing-path method
 * threads the product through so that {@see CurrencyScaleResolverInterface::getScaleSafe()}
 * can derive the scale from the product's company currency without relying on a
 * bound CompanyContext (which is absent in queued jobs / console commands).
 */
class MarginService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly MarginResolver $resolver,
    ) {}

    /**
     * Margin indicator levels
     */
    public const LEVEL_GREEN = 'green';   // Above target

    public const LEVEL_YELLOW = 'yellow'; // Below target, above minimum

    public const LEVEL_ORANGE = 'orange'; // Below minimum, above cost

    public const LEVEL_RED = 'red';       // Below cost (loss)

    /**
     * Margin-percentage values are compared/returned at 2 decimal places.
     * Percent is NOT currency-scaled — this constant is never threaded through product.
     */
    private const MARGIN_SCALE = 2;

    /**
     * Queue-safe, currency-aware scale resolution.
     *
     * When $product is provided the scale is derived from $product->company->currency
     * via the static ISO 4217 map (no CompanyContext required — safe in queue workers).
     * When $product is null or its company/currency is unavailable, falls back to 3
     * (the safe maximum for TND / non-EUR currencies).
     */
    private function scale(?Product $product = null): int
    {
        $currency = $product?->company?->currency;

        return $this->scaleResolver->getScaleSafe($currency, 3);
    }

    /**
     * Intermediate bcmath precision: money scale plus headroom so that
     * division/multiplication does not lose precision before the final round.
     */
    private function intermediateScale(?Product $product = null): int
    {
        return $this->scale($product) + 2;
    }

    /**
     * Round a numeric string half-away-from-zero to $scale, using pure bcmath
     * (no float). Delegates to the shared {@see CurrencyScale::bcround()} so the
     * round-half-up boundary semantics are centralised across margin, WAC and
     * COGS code.
     *
     * @param  numeric-string  $value  A well-formed numeric string
     * @return numeric-string
     */
    private function bcRoundHalfUp(string $value, int $scale): string
    {
        return CurrencyScale::bcround($value, $scale);
    }

    /**
     * Normalise any well-formed numeric input to a bcmath-safe numeric string.
     *
     * Floats are routed through CurrencyScale::bcformat() to avoid scientific
     * notation; strings/ints pass through unchanged so an exact decimal string
     * from a validated request is preserved bit-for-bit. Non-numeric or empty
     * input collapses to "0" — callers always feed validator-checked values.
     *
     * @return numeric-string
     */
    private function toNumericString(string|int|float|null $value): string
    {
        if ($value === null) {
            return '0';
        }

        if (is_float($value)) {
            return CurrencyScale::bcformat($value, $this->intermediateScale());
        }

        $str = trim((string) $value);

        return is_numeric($str) ? $str : '0';
    }

    /**
     * Get effective margins for a product via 3-level hierarchy
     * (product override → category chain → company default → hard-coded fallback).
     *
     * Delegates to {@see MarginResolver::resolve()} and maps the resulting
     * {@see EffectiveMargins} DTO to the back-compat array shape expected by callers.
     *
     * @return array{target_margin: string, minimum_margin: string, source: string, minimum_clamped: bool}
     */
    public function getEffectiveMargins(Product $product): array
    {
        $effective = $this->resolver->resolve($product);

        return [
            'target_margin'   => $effective->target_margin,
            'minimum_margin'  => $effective->minimum_margin,
            'source'          => $effective->target_source->value,
            'minimum_clamped' => $effective->minimum_clamped,
        ];
    }

    /**
     * Compute sell price from cost and a target-margin percentage, using bcmath.
     *
     * price = cost * (1 + margin / 100), rounded once to the money scale.
     *
     * @param  numeric-string  $cost
     * @param  numeric-string  $margin
     * @return numeric-string
     */
    private function priceFromMargin(string $cost, string $margin, ?Product $product = null): string
    {
        $inter = $this->intermediateScale($product);

        // factor = 1 + (margin / 100)
        $factor = bcadd('1', bcdiv($margin, '100', $inter), $inter);
        $raw = bcmul($cost, $factor, $inter);

        return $this->bcRoundHalfUp($raw, $this->scale($product));
    }

    /**
     * Calculate suggested sell price based on cost and target margin.
     */
    public function getSuggestedPrice(Product $product): float
    {
        $cost = $this->toNumericString($product->cost_price ?? '0');
        $margins = $this->getEffectiveMargins($product);

        // cost <= 0 → fall back to current sale price
        if (bccomp($cost, '0', $this->intermediateScale($product)) <= 0) {
            return (float) CurrencyScale::bcformat(
                $this->toNumericString($product->sale_price ?? '0'),
                $this->scale($product),
            );
        }

        return (float) $this->priceFromMargin($cost, $margins['target_margin'], $product);
    }

    /**
     * Update product sale price based on current cost and target margin.
     *
     * This method is called automatically after WAC updates.
     * Manual-priced products (pricing_mode === Manual) are skipped entirely.
     * Queue-safe: resolves monetary scale from the product's company currency,
     * never from CompanyContext (which is absent in queued jobs).
     *
     * @return bool True if sale price was updated
     */
    public function updateSalePrice(Product $product): bool
    {
        // Manual products own their price — never auto-reprice them.
        if ($product->pricing_mode === PricingMode::Manual) {
            return false;
        }

        // Ensure company is loaded before scale resolution (queue-safe: no CompanyContext).
        $product->loadMissing('company');

        $cost = $this->toNumericString($product->cost_price ?? '0');
        $scale = $this->scale($product);

        // Don't update if no cost
        if (bccomp($cost, '0', $this->intermediateScale($product)) <= 0) {
            return false;
        }

        // Get target margin (product → category chain → company → fallback)
        $margins = $this->getEffectiveMargins($product);

        // Calculate new sale price (rounded to money scale)
        $newSalePrice = $this->priceFromMargin($cost, $margins['target_margin'], $product);

        // Only update if different at the money scale (avoid unnecessary writes)
        $currentSalePrice = CurrencyScale::bcformat(
            $this->toNumericString($product->sale_price ?? '0'),
            $scale,
        );

        if (bccomp($newSalePrice, $currentSalePrice, $scale) === 0) {
            return false;
        }

        // Update sale price
        $product->sale_price = $newSalePrice;
        $product->save();

        return true;
    }

    /**
     * Calculate actual margin percentage for a given sell price.
     *
     * margin% = ((sellPrice - cost) / cost) * 100, computed via bcmath and
     * rounded once to MARGIN_SCALE. Returns null when cost <= 0.
     */
    public function calculateMargin(string|int|float $cost, string|int|float $sellPrice): ?float
    {
        $costStr = $this->toNumericString($cost);
        $sellStr = $this->toNumericString($sellPrice);
        $inter = $this->intermediateScale();

        if (bccomp($costStr, '0', $inter) <= 0) {
            return null;
        }

        // ((sell - cost) / cost) * 100
        $diff = bcsub($sellStr, $costStr, $inter + 2);
        $ratio = bcdiv($diff, $costStr, $inter + 2);
        $percent = bcmul($ratio, '100', $inter + 2);

        return (float) $this->bcRoundHalfUp($percent, self::MARGIN_SCALE);
    }

    /**
     * Get margin indicator level for a sell price.
     *
     * @return array{level: string, message: string, actual_margin: float|null, target_margin?: float, minimum_margin?: float, loss_amount?: float}
     */
    public function getMarginLevel(Product $product, string|int|float $sellPrice): array
    {
        $cost = $this->toNumericString($product->cost_price ?? '0');
        $sell = $this->toNumericString($sellPrice);
        $margins = $this->getEffectiveMargins($product);
        $actualMargin = $this->calculateMargin($cost, $sell);
        $inter = $this->intermediateScale($product);
        $scale = $this->scale($product);

        if (bccomp($cost, '0', $inter) <= 0) {
            return [
                'level' => self::LEVEL_GREEN,
                'message' => 'No cost data',
                'actual_margin' => null,
            ];
        }

        // Below cost (sell < cost) → loss
        if (bccomp($sell, $cost, $scale) < 0) {
            return [
                'level' => self::LEVEL_RED,
                'message' => 'Below cost - LOSS',
                'actual_margin' => $actualMargin,
                'loss_amount' => (float) $this->bcRoundHalfUp(
                    bcsub($cost, $sell, $inter),
                    $scale,
                ),
            ];
        }

        $actualMarginStr = CurrencyScale::bcformat((string) $actualMargin, self::MARGIN_SCALE);

        // Below minimum margin (actual < minimum)
        if (bccomp($actualMarginStr, $margins['minimum_margin'], self::MARGIN_SCALE) < 0) {
            return [
                'level' => self::LEVEL_ORANGE,
                'message' => 'Below minimum margin',
                'actual_margin' => $actualMargin,
                'minimum_margin' => (float) $margins['minimum_margin'],
            ];
        }

        // Below target margin (actual < target)
        if (bccomp($actualMarginStr, $margins['target_margin'], self::MARGIN_SCALE) < 0) {
            return [
                'level' => self::LEVEL_YELLOW,
                'message' => 'Below target margin',
                'actual_margin' => $actualMargin,
                'target_margin' => (float) $margins['target_margin'],
            ];
        }

        return [
            'level' => self::LEVEL_GREEN,
            'message' => 'Above target margin',
            'actual_margin' => $actualMargin,
        ];
    }

    /**
     * Check if user can sell at this price.
     *
     * @return array{allowed: bool, reason: string|null, requires_permission?: string, margin_level?: array<string, mixed>}
     */
    public function canSellAtPrice(
        Product $product,
        string|int|float $sellPrice,
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
}
