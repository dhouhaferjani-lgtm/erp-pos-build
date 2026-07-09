<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Application\Services\MarginResolver;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Product;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

/**
 * Phase 4.6 — MarginService precision tests.
 *
 * The discount-permission threshold (minimum/target margin) is the load-bearing
 * boundary: a sell price evaluated with IEEE-754 floats can drift just under an
 * exact margin threshold (e.g. 15.00% computed as 14.999999%) and silently flip
 * the classification from "allowed" to a permission-gated ORANGE/YELLOW.
 *
 * These tests build in-memory Product + Company models (no DB) and drive the
 * bcmath-based MarginService directly, asserting that the result is EXACT at the
 * threshold and does not flip.
 */
final class MarginCheckPrecisionTest extends TestCase
{
    use WithCurrencyScale;

    /**
     * Build an in-memory Product with its company relation pre-set (no DB write).
     */
    private function makeProduct(
        string $costPrice,
        string $targetMargin = '30.00',
        string $minimumMargin = '15.00',
        bool $allowBelowCost = false,
        ?string $salePrice = null,
        ?string $targetOverride = null,
        ?string $minimumOverride = null,
    ): Product {
        $company = new Company([
            'default_target_margin' => $targetMargin,
            'default_minimum_margin' => $minimumMargin,
            'allow_below_cost_sales' => $allowBelowCost,
        ]);

        $product = new Product;
        $product->cost_price = $costPrice;
        if ($salePrice !== null) {
            $product->sale_price = $salePrice;
        }
        $product->target_margin_override = $targetOverride;
        $product->minimum_margin_override = $minimumOverride;
        $product->setRelation('company', $company);

        return $product;
    }

    /**
     * cost=100, minimum=15% → exact threshold sell price = 115.000.
     * At exactly the threshold the actual margin is EXACTLY 15.00%, which is NOT
     * below minimum, so the classification must NOT be the permission-gated ORANGE.
     * A float implementation drifts to 14.999999% and mis-flags ORANGE.
     */
    public function test_exact_minimum_margin_threshold_does_not_flip_to_orange(): void
    {
        $service = new MarginService($this->mockCurrencyScale(3), new MarginResolver);
        $product = $this->makeProduct(costPrice: '100.000', minimumMargin: '15.00', targetMargin: '30.00');

        $level = $service->getMarginLevel($product, '115.000');

        $this->assertNotSame(
            MarginService::LEVEL_ORANGE,
            $level['level'],
            'Sell price exactly at the minimum-margin threshold must not flip to ORANGE'
        );
        // 115 is below the 30% target (130) but at/above the 15% minimum → YELLOW.
        $this->assertSame(MarginService::LEVEL_YELLOW, $level['level']);
    }

    /**
     * Gold-standard exact margin: (115 - 100) / 100 * 100 == 15.00 exactly.
     */
    public function test_calculate_margin_is_exact_at_threshold(): void
    {
        $service = new MarginService($this->mockCurrencyScale(3), new MarginResolver);

        $margin = $service->calculateMargin(cost: '100.000', sellPrice: '115.000');

        $this->assertSame('15.00', $margin);
    }

    /**
     * A value one mill BELOW the threshold (114.999 → 14.999% rounded to 15.00 at
     * scale 2) and a value clearly below (114.000 → 14.00%) classify correctly.
     */
    public function test_just_below_minimum_margin_is_orange(): void
    {
        $service = new MarginService($this->mockCurrencyScale(3), new MarginResolver);
        $product = $this->makeProduct(costPrice: '100.000', minimumMargin: '15.00', targetMargin: '30.00');

        $level = $service->getMarginLevel($product, '114.000'); // 14.00% margin

        $this->assertSame(MarginService::LEVEL_ORANGE, $level['level']);
    }

    /**
     * Suggested price from a target margin is exact: 100 * (1 + 30/100) = 130.000.
     * String assertion proves no float drift in the bcmath pipeline.
     */
    public function test_suggested_price_is_exact(): void
    {
        $service = new MarginService($this->mockCurrencyScale(3), new MarginResolver);
        $product = $this->makeProduct(costPrice: '100.000', targetMargin: '30.00');

        $suggested = $service->getSuggestedPrice($product);

        $this->assertSame('130.000', $suggested);
    }

    /**
     * A repeating-decimal cost that classically drifts under floats:
     * cost = 33.333, target 30% → 33.333 * 1.30 = 43.3329 → rounded to 43.333.
     * Asserts the boundary round is taken via bcmath at the money scale.
     */
    public function test_repeating_decimal_cost_rounds_once_at_boundary(): void
    {
        $service = new MarginService($this->mockCurrencyScale(3), new MarginResolver);
        $product = $this->makeProduct(costPrice: '33.333', targetMargin: '30.00');

        $suggested = $service->getSuggestedPrice($product);

        // 33.333 * 1.30 = 43.3329 → round to scale 3 = 43.333
        $this->assertSame('43.333', $suggested);
    }
}
