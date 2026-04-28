<?php

declare(strict_types=1);

namespace Tests\Unit\Promotion;

use App\Modules\Promotion\Domain\Entities\Promotion;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\Enums\DiscountType;
use App\Modules\Promotion\Domain\Enums\PromotionStatus;
use App\Modules\Promotion\Domain\Enums\PromotionType;
use App\Modules\Promotion\Domain\Services\PromotionEvaluationService;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\CartItemContext;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PromotionEvaluationServiceTest extends TestCase
{
    /**
     * Frozen wall-clock used by every test in this file. Pinning this prevents
     * `Promotion::isCurrentlyActive()` (which falls back to `Carbon::now()`)
     * from flipping verdicts based on the actual calendar day-of-week or hour.
     * The exact value is arbitrary as long as it's stable.
     */
    private const FROZEN_NOW = '2026-04-15 12:00:00';

    private PromotionEvaluationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the global Carbon clock so every test sees the same wall-clock
        // regardless of when the suite runs.
        CarbonImmutable::setTestNow(self::FROZEN_NOW);
        Carbon::setTestNow(self::FROZEN_NOW);

        $scaleResolver = $this->createMock(CurrencyScaleResolverInterface::class);
        $scaleResolver->method('getScale')->willReturn(2);
        $this->service = new PromotionEvaluationService($scaleResolver);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeCart(array $items = [], string $subtotal = '100.00'): CartContext
    {
        return new CartContext(
            tenantId: 'tenant-1',
            companyId: 'company-1',
            items: $items,
            subtotal: $subtotal,
            appliedAt: Carbon::now()->toIso8601String(),
        );
    }

    private function makeItem(
        string $productId = 'prod-1',
        ?string $categoryId = null,
        int $quantity = 1,
        string $unitPrice = '10.00',
        ?string $lineTotal = null,
    ): CartItemContext {
        return new CartItemContext(
            productId: $productId,
            categoryId: $categoryId,
            quantity: $quantity,
            unitPrice: $unitPrice,
            lineTotal: $lineTotal ?? bcmul($unitPrice, (string) $quantity, 2),
        );
    }

    private function makePromotion(array $overrides = []): Promotion
    {
        $promotion = new Promotion;
        $promotion->id = $overrides['id'] ?? 'promo-1';
        $promotion->name = $overrides['name'] ?? 'Test Promotion';
        $promotion->type = $overrides['type'] ?? PromotionType::HappyHour;
        $promotion->status = $overrides['status'] ?? PromotionStatus::Active;
        $promotion->priority = $overrides['priority'] ?? 0;
        $promotion->is_exclusive = $overrides['is_exclusive'] ?? false;
        $promotion->stacking_group = $overrides['stacking_group'] ?? 'default';
        $promotion->discount_type = $overrides['discount_type'] ?? DiscountType::Percentage;
        $promotion->discount_value = $overrides['discount_value'] ?? '10';
        $promotion->max_discount_amount = $overrides['max_discount_amount'] ?? null;
        $promotion->applies_to = $overrides['applies_to'] ?? DiscountAppliesTo::Transaction;
        $promotion->conditions = $overrides['conditions'] ?? [];

        return $promotion;
    }

    // -- Happy Hour Tests --

    public function test_happy_hour_percentage_discount(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::HappyHour,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '10',
        ]);

        $cart = $this->makeCart(subtotal: '200.00');
        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertEquals('20.00', $discounts[0]->discountAmount);
        $this->assertEquals(DiscountAppliesTo::Transaction, $discounts[0]->appliesTo);
    }

    public function test_happy_hour_fixed_discount(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::HappyHour,
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '15.00',
        ]);

        $cart = $this->makeCart(subtotal: '200.00');
        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertEquals('15.00', $discounts[0]->discountAmount);
    }

    public function test_happy_hour_capped_at_max_discount(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::HappyHour,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '50',
            'max_discount_amount' => '25.00',
        ]);

        $cart = $this->makeCart(subtotal: '200.00');
        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertEquals('25.00', $discounts[0]->discountAmount);
    }

    public function test_happy_hour_capped_at_subtotal(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::HappyHour,
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '500.00',
        ]);

        $cart = $this->makeCart(subtotal: '200.00');
        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertEquals('200.00', $discounts[0]->discountAmount);
    }

    // -- Buy X Get Y Tests --

    public function test_buy_x_get_y_free_cheapest(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::BuyXGetY,
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'conditions' => [
                'qualifying_product_ids' => ['prod-1', 'prod-2'],
                'trigger_qty' => 3,
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-1', quantity: 2, unitPrice: '20.00'),
            $this->makeItem('prod-2', quantity: 1, unitPrice: '10.00'),
        ], subtotal: '50.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertEquals('10.00', $discounts[0]->discountAmount);
        $this->assertEquals(DiscountAppliesTo::CheapestItem, $discounts[0]->appliesTo);
        $this->assertEquals('prod-2', $discounts[0]->targetProductId);
    }

    public function test_buy_x_get_y_not_enough_qty(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::BuyXGetY,
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'conditions' => [
                'qualifying_product_ids' => ['prod-1'],
                'trigger_qty' => 5,
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-1', quantity: 2, unitPrice: '20.00'),
        ], subtotal: '40.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(0, $discounts);
    }

    // -- Volume Discount Tests --

    public function test_volume_discount_min_qty(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::VolumeDiscount,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '15',
            'applies_to' => DiscountAppliesTo::Transaction,
            'conditions' => [
                'min_qty' => 5,
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-1', quantity: 3, unitPrice: '10.00'),
            $this->makeItem('prod-2', quantity: 3, unitPrice: '10.00'),
        ], subtotal: '60.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertEquals('9.00', $discounts[0]->discountAmount);
    }

    public function test_volume_discount_below_threshold(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::VolumeDiscount,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '15',
            'applies_to' => DiscountAppliesTo::Transaction,
            'conditions' => [
                'min_qty' => 10,
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-1', quantity: 3, unitPrice: '10.00'),
        ], subtotal: '30.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(0, $discounts);
    }

    public function test_volume_discount_min_amount(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::VolumeDiscount,
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '10.00',
            'applies_to' => DiscountAppliesTo::Transaction,
            'conditions' => [
                'min_amount' => '50',
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-1', quantity: 3, unitPrice: '20.00'),
        ], subtotal: '60.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertEquals('10.00', $discounts[0]->discountAmount);
    }

    // -- Category Discount Tests --

    public function test_category_discount_applies_to_matching_items(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::CategoryDiscount,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '20',
            'conditions' => [
                'category_ids' => ['cat-beverages'],
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-1', 'cat-beverages', quantity: 2, unitPrice: '5.00'),
            $this->makeItem('prod-2', 'cat-food', quantity: 1, unitPrice: '20.00'),
        ], subtotal: '30.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        // 20% of beverages (2 * 5.00 = 10.00) = 2.00
        $this->assertEquals('2.00', $discounts[0]->discountAmount);
        $this->assertEquals(DiscountAppliesTo::QualifyingItems, $discounts[0]->appliesTo);
    }

    public function test_category_discount_no_matching_items(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::CategoryDiscount,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '20',
            'conditions' => [
                'category_ids' => ['cat-electronics'],
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-1', 'cat-food', quantity: 1, unitPrice: '20.00'),
        ], subtotal: '20.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(0, $discounts);
    }

    // -- Combo Discount Tests --

    public function test_combo_discount_all_products_present(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::ComboDiscount,
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '5.00',
            'conditions' => [
                'combo_product_ids' => ['prod-burger', 'prod-fries', 'prod-drink'],
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-burger', unitPrice: '15.00'),
            $this->makeItem('prod-fries', unitPrice: '5.00'),
            $this->makeItem('prod-drink', unitPrice: '3.00'),
        ], subtotal: '23.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertEquals('5.00', $discounts[0]->discountAmount);
    }

    public function test_combo_discount_missing_product(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::ComboDiscount,
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '5.00',
            'conditions' => [
                'combo_product_ids' => ['prod-burger', 'prod-fries', 'prod-drink'],
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-burger', unitPrice: '15.00'),
            $this->makeItem('prod-fries', unitPrice: '5.00'),
        ], subtotal: '20.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(0, $discounts);
    }

    // -- Promotion Model Tests --

    public function test_promotion_is_currently_active_checks_status(): void
    {
        $promotion = $this->makePromotion(['status' => PromotionStatus::Draft]);
        $this->assertFalse($promotion->isCurrentlyActive());

        $promotion = $this->makePromotion(['status' => PromotionStatus::Active]);
        $this->assertTrue($promotion->isCurrentlyActive());

        $promotion = $this->makePromotion(['status' => PromotionStatus::Paused]);
        $this->assertFalse($promotion->isCurrentlyActive());
    }

    public function test_promotion_is_currently_active_checks_date_range(): void
    {
        $now = Carbon::parse('2026-03-02 12:00:00');

        // Within range — use makePromotion helper which sets attributes without DB
        $promotion = new Promotion;
        $promotion->status = PromotionStatus::Active;
        // Set raw attributes to bypass Eloquent date casting (no DB in unit test)
        $ref = new \ReflectionProperty(Promotion::class, 'attributes');
        $ref->setAccessible(true);

        $attrs = $ref->getValue($promotion);
        $attrs['starts_at'] = '2026-03-01 00:00:00';
        $attrs['ends_at'] = '2026-03-31 00:00:00';
        $attrs['status'] = 'active';
        $ref->setValue($promotion, $attrs);

        $this->assertTrue($promotion->isCurrentlyActive($now));

        // Before start
        $attrs['starts_at'] = '2026-03-10 00:00:00';
        $ref->setValue($promotion, $attrs);
        $this->assertFalse($promotion->isCurrentlyActive($now));

        // After end
        $attrs['starts_at'] = '2026-02-01 00:00:00';
        $attrs['ends_at'] = '2026-02-28 00:00:00';
        $ref->setValue($promotion, $attrs);
        $this->assertFalse($promotion->isCurrentlyActive($now));
    }

    public function test_promotion_is_currently_active_checks_day_of_week(): void
    {
        // 2026-03-02 is a Monday (day 1)
        $now = Carbon::parse('2026-03-02 12:00:00');

        $promotion = $this->makePromotion(['status' => PromotionStatus::Active]);
        $promotion->days_of_week = [1, 2, 3]; // Mon, Tue, Wed
        $this->assertTrue($promotion->isCurrentlyActive($now));

        $promotion->days_of_week = [5, 6, 7]; // Fri, Sat, Sun
        $this->assertFalse($promotion->isCurrentlyActive($now));
    }

    public function test_promotion_is_currently_active_checks_time_window(): void
    {
        $now = Carbon::parse('2026-03-02 14:30:00');

        $promotion = $this->makePromotion(['status' => PromotionStatus::Active]);
        $promotion->time_from = '09:00';
        $promotion->time_until = '17:00';
        $this->assertTrue($promotion->isCurrentlyActive($now));

        $promotion->time_from = '15:00';
        $promotion->time_until = '17:00';
        $this->assertFalse($promotion->isCurrentlyActive($now));
    }

    public function test_promotion_is_currently_active_checks_usage_limit(): void
    {
        $promotion = $this->makePromotion(['status' => PromotionStatus::Active]);
        $promotion->usage_limit = 100;
        $promotion->usage_count = 99;
        $this->assertTrue($promotion->isCurrentlyActive());

        $promotion->usage_count = 100;
        $this->assertFalse($promotion->isCurrentlyActive());
    }

    // -- Stacking Metadata Tests --

    public function test_discount_carries_stacking_metadata(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::HappyHour,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '10',
            'is_exclusive' => true,
            'stacking_group' => 'auto_discounts',
            'priority' => 5,
        ]);

        $cart = $this->makeCart(subtotal: '100.00');
        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertTrue($discounts[0]->isExclusive);
        $this->assertEquals('auto_discounts', $discounts[0]->stackingGroup);
        $this->assertEquals(5, $discounts[0]->priority);
    }

    // -- Buy X Get Y: Reward Products & Repeating Triggers --

    public function test_buy_x_get_y_with_reward_product_discounts_reward_item(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::BuyXGetY,
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'conditions' => [
                'qualifying_product_ids' => ['frapp'],
                'trigger_qty' => 1,
                'reward_product_ids' => ['croissant'],
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('frapp', quantity: 1, unitPrice: '7.50'),
            $this->makeItem('croissant', quantity: 1, unitPrice: '3.00'),
        ], subtotal: '10.50');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertEquals('3.00', $discounts[0]->discountAmount);
        $this->assertEquals(DiscountAppliesTo::SpecificItem, $discounts[0]->appliesTo);
        $this->assertEquals('croissant', $discounts[0]->targetProductId);
    }

    public function test_buy_x_get_y_reward_not_in_cart_returns_empty(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::BuyXGetY,
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'conditions' => [
                'qualifying_product_ids' => ['frapp'],
                'trigger_qty' => 1,
                'reward_product_ids' => ['croissant'],
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('frapp', quantity: 1, unitPrice: '7.50'),
        ], subtotal: '7.50');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(0, $discounts);
    }

    public function test_buy_x_get_y_repeating_trigger_gives_multiple_rewards(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::BuyXGetY,
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'conditions' => [
                'qualifying_product_ids' => ['prod-1', 'prod-2'],
                'trigger_qty' => 3,
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-1', quantity: 4, unitPrice: '20.00'),
            $this->makeItem('prod-2', quantity: 2, unitPrice: '10.00'),
        ], subtotal: '100.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        // 6 qualifying / 3 trigger_qty = 2 triggers
        // Cheapest qualifying = prod-2 @ $10, available qty = 2
        // 2 triggers * 1 reward * $10 = $20
        $this->assertEquals('20.00', $discounts[0]->discountAmount);
    }

    public function test_buy_x_get_y_repeating_with_reward_products(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::BuyXGetY,
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'conditions' => [
                'qualifying_product_ids' => ['frapp'],
                'trigger_qty' => 1,
                'reward_product_ids' => ['croissant'],
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('frapp', quantity: 2, unitPrice: '7.50'),
            $this->makeItem('croissant', quantity: 2, unitPrice: '3.00'),
        ], subtotal: '21.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        // 2 frapps / 1 trigger_qty = 2 triggers, 2 croissants available
        // 2 * $3.00 = $6.00
        $this->assertEquals('6.00', $discounts[0]->discountAmount);
    }

    public function test_buy_x_get_y_reward_qty_capped_at_available(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::BuyXGetY,
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'conditions' => [
                'qualifying_product_ids' => ['frapp'],
                'trigger_qty' => 1,
                'reward_product_ids' => ['croissant'],
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('frapp', quantity: 3, unitPrice: '7.50'),
            $this->makeItem('croissant', quantity: 1, unitPrice: '3.00'),
        ], subtotal: '25.50');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        // 3 triggers but only 1 croissant available → $3.00
        $this->assertEquals('3.00', $discounts[0]->discountAmount);
    }

    public function test_buy_x_get_y_backward_compat_no_reward_ids(): void
    {
        // Same as existing free_cheapest test — verify backward compatibility
        $promotion = $this->makePromotion([
            'type' => PromotionType::BuyXGetY,
            'discount_type' => DiscountType::FreeItem,
            'discount_value' => '100',
            'conditions' => [
                'qualifying_product_ids' => ['prod-1', 'prod-2'],
                'trigger_qty' => 3,
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('prod-1', quantity: 2, unitPrice: '20.00'),
            $this->makeItem('prod-2', quantity: 1, unitPrice: '10.00'),
        ], subtotal: '50.00');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        $this->assertEquals('10.00', $discounts[0]->discountAmount);
        $this->assertEquals(DiscountAppliesTo::CheapestItem, $discounts[0]->appliesTo);
        $this->assertEquals('prod-2', $discounts[0]->targetProductId);
    }

    public function test_buy_x_get_y_percentage_on_reward(): void
    {
        $promotion = $this->makePromotion([
            'type' => PromotionType::BuyXGetY,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '50',
            'conditions' => [
                'qualifying_product_ids' => ['frapp'],
                'trigger_qty' => 1,
                'reward_product_ids' => ['croissant'],
            ],
        ]);

        $cart = $this->makeCart(items: [
            $this->makeItem('frapp', quantity: 1, unitPrice: '7.50'),
            $this->makeItem('croissant', quantity: 1, unitPrice: '3.00'),
        ], subtotal: '10.50');

        $discounts = $this->service->evaluate($promotion, $cart);

        $this->assertCount(1, $discounts);
        // 50% of $3.00 = $1.50
        $this->assertEquals('1.50', $discounts[0]->discountAmount);
        $this->assertEquals(DiscountAppliesTo::SpecificItem, $discounts[0]->appliesTo);
    }
}
