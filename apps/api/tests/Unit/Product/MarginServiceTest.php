<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use App\Modules\Product\Application\Services\MarginService;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

class MarginServiceTest extends TestCase
{
    use WithCurrencyScale;

    private MarginService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MarginService($this->mockCurrencyScale(2));
    }

    public function test_calculate_margin_with_valid_inputs(): void
    {
        $result = $this->service->calculateMargin(cost: 100.00, sellPrice: 130.00);

        // (130-100)/100 * 100 = 30%
        $this->assertEquals(30.00, $result);
    }

    public function test_calculate_margin_with_50_percent_margin(): void
    {
        $result = $this->service->calculateMargin(cost: 50.00, sellPrice: 75.00);

        // (75-50)/50 * 100 = 50%
        $this->assertEquals(50.00, $result);
    }

    public function test_calculate_margin_with_negative_margin(): void
    {
        $result = $this->service->calculateMargin(cost: 100.00, sellPrice: 90.00);

        // (90-100)/100 * 100 = -10%
        $this->assertEquals(-10.00, $result);
    }

    public function test_calculate_margin_with_zero_cost(): void
    {
        $result = $this->service->calculateMargin(cost: 0.00, sellPrice: 100.00);

        $this->assertNull($result);
    }

    public function test_margin_level_constants_exist(): void
    {
        $this->assertEquals('green', MarginService::LEVEL_GREEN);
        $this->assertEquals('yellow', MarginService::LEVEL_YELLOW);
        $this->assertEquals('orange', MarginService::LEVEL_ORANGE);
        $this->assertEquals('red', MarginService::LEVEL_RED);
    }

    public function test_service_exists(): void
    {
        $this->assertTrue(class_exists(MarginService::class));
    }

    public function test_service_has_get_effective_margins_method(): void
    {
        $this->assertTrue(method_exists(MarginService::class, 'getEffectiveMargins'));
    }

    public function test_service_has_get_suggested_price_method(): void
    {
        $this->assertTrue(method_exists(MarginService::class, 'getSuggestedPrice'));
    }

    public function test_service_has_calculate_margin_method(): void
    {
        $this->assertTrue(method_exists(MarginService::class, 'calculateMargin'));
    }

    public function test_service_has_get_margin_level_method(): void
    {
        $this->assertTrue(method_exists(MarginService::class, 'getMarginLevel'));
    }

    public function test_service_has_can_sell_at_price_method(): void
    {
        $this->assertTrue(method_exists(MarginService::class, 'canSellAtPrice'));
    }
}
