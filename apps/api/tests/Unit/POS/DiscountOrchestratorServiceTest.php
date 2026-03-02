<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Coupon\Domain\Contracts\CouponValidatorContract;
use App\Modules\POS\Application\Services\DiscountOrchestratorService;
use App\Modules\POS\Domain\Services\DiscountStackingService;
use App\Modules\Promotion\Domain\Contracts\PromotionEvaluatorContract;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\CartItemContext;
use App\Modules\Promotion\Domain\ValueObjects\PromotionDiscount;
use PHPUnit\Framework\TestCase;

final class DiscountOrchestratorServiceTest extends TestCase
{
    private DiscountOrchestratorService $orchestrator;

    /** @var PromotionEvaluatorContract&\PHPUnit\Framework\MockObject\MockObject */
    private PromotionEvaluatorContract $promotionEvaluator;

    /** @var CouponValidatorContract&\PHPUnit\Framework\MockObject\MockObject */
    private CouponValidatorContract $couponValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->promotionEvaluator = $this->createMock(PromotionEvaluatorContract::class);
        $this->couponValidator = $this->createMock(CouponValidatorContract::class);

        $this->orchestrator = new DiscountOrchestratorService(
            promotionEvaluator: $this->promotionEvaluator,
            couponValidator: $this->couponValidator,
            stackingService: new DiscountStackingService(),
        );
    }

    private function makeCart(string $subtotal = '100.00'): CartContext
    {
        return new CartContext(
            tenantId: 'tenant-1',
            companyId: 'company-1',
            items: [
                new CartItemContext(
                    productId: 'product-1',
                    categoryId: 'cat-1',
                    quantity: 2,
                    unitPrice: '25.00',
                    lineTotal: '50.00',
                ),
                new CartItemContext(
                    productId: 'product-2',
                    categoryId: 'cat-2',
                    quantity: 1,
                    unitPrice: '50.00',
                    lineTotal: '50.00',
                ),
            ],
            subtotal: $subtotal,
            appliedAt: '2026-03-02T12:00:00+00:00',
        );
    }

    public function test_no_discounts_returns_empty_breakdown(): void
    {
        $this->promotionEvaluator->method('evaluateCart')->willReturn([]);

        $result = $this->orchestrator->resolve($this->makeCart());

        $this->assertSame('0.00', $result->totalTransactionDiscount);
        $this->assertSame([], $result->lines);
    }

    public function test_manual_discount_only(): void
    {
        $this->promotionEvaluator->method('evaluateCart')->willReturn([]);

        $result = $this->orchestrator->resolve(
            cart: $this->makeCart(),
            manualDiscountAmount: '15.00',
            manualDiscountReason: 'VIP customer',
        );

        $this->assertSame('15.00', $result->totalTransactionDiscount);
        $this->assertCount(1, $result->lines);
        $this->assertSame('manual', $result->lines[0]->source);
        $this->assertSame('VIP customer', $result->lines[0]->label);
    }

    public function test_zero_manual_discount_is_ignored(): void
    {
        $this->promotionEvaluator->method('evaluateCart')->willReturn([]);

        $result = $this->orchestrator->resolve(
            cart: $this->makeCart(),
            manualDiscountAmount: '0.00',
        );

        $this->assertSame('0.00', $result->totalTransactionDiscount);
        $this->assertSame([], $result->lines);
    }

    public function test_promotion_discounts_collected(): void
    {
        $this->promotionEvaluator->method('evaluateCart')->willReturn([
            new PromotionDiscount(
                promotionId: 'promo-1',
                promotionName: 'Happy Hour 10%',
                discountAmount: '10.00',
                appliesTo: DiscountAppliesTo::Transaction,
                targetProductId: null,
                isExclusive: false,
                stackingGroup: 'promo',
                priority: 10,
            ),
        ]);

        $result = $this->orchestrator->resolve($this->makeCart());

        $this->assertSame('10.00', $result->totalTransactionDiscount);
        $this->assertCount(1, $result->lines);
        $this->assertSame('promotion', $result->lines[0]->source);
    }

    public function test_coupon_discount_collected(): void
    {
        $this->promotionEvaluator->method('evaluateCart')->willReturn([]);
        $this->couponValidator->method('validateAndCalculate')->willReturn(
            new PromotionDiscount(
                promotionId: 'coupon-1',
                promotionName: 'WELCOME10',
                discountAmount: '10.00',
                appliesTo: DiscountAppliesTo::Transaction,
                targetProductId: null,
                isExclusive: false,
                stackingGroup: 'coupon',
                priority: 30,
            ),
        );

        $result = $this->orchestrator->resolve(
            cart: $this->makeCart(),
            couponCode: 'WELCOME10',
            customerId: 'customer-1',
        );

        $this->assertSame('10.00', $result->totalTransactionDiscount);
        $this->assertSame('coupon', $result->lines[0]->source);
    }

    public function test_loyalty_discount_collected(): void
    {
        $this->promotionEvaluator->method('evaluateCart')->willReturn([]);

        $result = $this->orchestrator->resolve(
            cart: $this->makeCart(),
            loyaltyDiscountAmount: '5.00',
            loyaltyRewardId: 'reward-1',
        );

        $this->assertSame('5.00', $result->totalTransactionDiscount);
        $this->assertSame('loyalty', $result->lines[0]->source);
        $this->assertSame('reward-1', $result->lines[0]->referenceId);
    }

    public function test_all_sources_combine(): void
    {
        $this->promotionEvaluator->method('evaluateCart')->willReturn([
            new PromotionDiscount(
                promotionId: 'promo-1',
                promotionName: 'Happy Hour',
                discountAmount: '10.00',
                appliesTo: DiscountAppliesTo::Transaction,
                targetProductId: null,
                isExclusive: false,
                stackingGroup: 'promo',
                priority: 10,
            ),
        ]);
        $this->couponValidator->method('validateAndCalculate')->willReturn(
            new PromotionDiscount(
                promotionId: 'coupon-1',
                promotionName: 'SAVE5',
                discountAmount: '5.00',
                appliesTo: DiscountAppliesTo::Transaction,
                targetProductId: null,
                isExclusive: false,
                stackingGroup: 'coupon',
                priority: 30,
            ),
        );

        $result = $this->orchestrator->resolve(
            cart: $this->makeCart(),
            manualDiscountAmount: '8.00',
            manualDiscountReason: 'Manager override',
            couponCode: 'SAVE5',
            customerId: 'cust-1',
            loyaltyDiscountAmount: '3.00',
            loyaltyRewardId: 'reward-1',
        );

        // 10 (promo) + 5 (coupon) + 8 (manual) + 3 (loyalty) = 26
        $this->assertSame('26.00', $result->totalTransactionDiscount);
        $this->assertCount(4, $result->lines);
    }

    public function test_promotion_failure_does_not_block_checkout(): void
    {
        $this->promotionEvaluator->method('evaluateCart')
            ->willThrowException(new \RuntimeException('Database connection lost'));

        $result = $this->orchestrator->resolve(
            cart: $this->makeCart(),
            manualDiscountAmount: '10.00',
        );

        // Manual discount still applied despite promotion failure
        $this->assertSame('10.00', $result->totalTransactionDiscount);
        $this->assertCount(1, $result->lines);
    }

    public function test_coupon_failure_does_not_block_checkout(): void
    {
        $this->promotionEvaluator->method('evaluateCart')->willReturn([]);
        $this->couponValidator->method('validateAndCalculate')
            ->willThrowException(new \RuntimeException('Invalid coupon'));

        $result = $this->orchestrator->resolve(
            cart: $this->makeCart(),
            couponCode: 'BAD_CODE',
            manualDiscountAmount: '5.00',
        );

        // Manual discount still applied despite coupon failure
        $this->assertSame('5.00', $result->totalTransactionDiscount);
        $this->assertCount(1, $result->lines);
    }

    public function test_line_level_promotion_tracked_by_product(): void
    {
        $this->promotionEvaluator->method('evaluateCart')->willReturn([
            new PromotionDiscount(
                promotionId: 'promo-1',
                promotionName: 'Buy 2 get discount',
                discountAmount: '7.50',
                appliesTo: DiscountAppliesTo::SpecificItem,
                targetProductId: 'product-1',
                isExclusive: false,
                stackingGroup: 'promo',
                priority: 10,
            ),
        ]);

        $result = $this->orchestrator->resolve($this->makeCart());

        $this->assertSame('0.00', $result->totalTransactionDiscount);
        $this->assertArrayHasKey('product-1', $result->lineDiscounts);
        $this->assertSame('7.50', $result->lineDiscounts['product-1']);
    }

    public function test_null_coupon_result_is_skipped(): void
    {
        $this->promotionEvaluator->method('evaluateCart')->willReturn([]);
        $this->couponValidator->method('validateAndCalculate')->willReturn(null);

        $result = $this->orchestrator->resolve(
            cart: $this->makeCart(),
            couponCode: 'EXPIRED_CODE',
        );

        $this->assertSame('0.00', $result->totalTransactionDiscount);
        $this->assertSame([], $result->lines);
    }
}
