<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\POS\Domain\Services\DiscountStackingService;
use App\Modules\POS\Domain\ValueObjects\DiscountLine;
use PHPUnit\Framework\TestCase;
use Tests\Traits\WithCurrencyScale;

final class DiscountStackingServiceTest extends TestCase
{
    use WithCurrencyScale;

    private DiscountStackingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DiscountStackingService($this->mockCurrencyScale(3));
    }

    public function test_empty_candidates_returns_zero_breakdown(): void
    {
        $result = $this->service->resolve([], '100.00');

        $this->assertSame('0.00', $result->totalTransactionDiscount);
        $this->assertSame([], $result->lines);
        $this->assertSame([], $result->lineDiscounts);
    }

    public function test_single_manual_discount_passes_through(): void
    {
        $candidates = [
            new DiscountLine(
                source: 'manual',
                stackingGroup: 'manual',
                isExclusive: false,
                priority: 100,
                discountAmount: '10.00',
                label: 'Manual discount',
                referenceId: null,
                appliesTo: 'transaction',
            ),
        ];

        $result = $this->service->resolve($candidates, '100.00');

        $this->assertSame('10.000', $result->totalTransactionDiscount);
        $this->assertCount(1, $result->lines);
    }

    public function test_non_exclusive_same_group_stack_additively(): void
    {
        $candidates = [
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo',
                isExclusive: false,
                priority: 10,
                discountAmount: '5.00',
                label: 'Promo A',
                referenceId: 'promo-a',
                appliesTo: 'transaction',
            ),
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo',
                isExclusive: false,
                priority: 20,
                discountAmount: '3.00',
                label: 'Promo B',
                referenceId: 'promo-b',
                appliesTo: 'transaction',
            ),
        ];

        $result = $this->service->resolve($candidates, '100.00');

        $this->assertSame('8.000', $result->totalTransactionDiscount);
        $this->assertCount(2, $result->lines);
    }

    public function test_exclusive_wins_within_group(): void
    {
        $candidates = [
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo',
                isExclusive: false,
                priority: 20,
                discountAmount: '3.00',
                label: 'Non-exclusive promo',
                referenceId: 'promo-a',
                appliesTo: 'transaction',
            ),
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo',
                isExclusive: true,
                priority: 10,
                discountAmount: '15.00',
                label: 'Exclusive promo',
                referenceId: 'promo-b',
                appliesTo: 'transaction',
            ),
        ];

        $result = $this->service->resolve($candidates, '100.00');

        // Only the exclusive discount survives
        $this->assertSame('15.000', $result->totalTransactionDiscount);
        $this->assertCount(1, $result->lines);
        $this->assertSame('Exclusive promo', $result->lines[0]->label);
    }

    public function test_highest_priority_exclusive_wins(): void
    {
        $candidates = [
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo',
                isExclusive: true,
                priority: 50,
                discountAmount: '5.00',
                label: 'Low priority exclusive',
                referenceId: 'promo-a',
                appliesTo: 'transaction',
            ),
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo',
                isExclusive: true,
                priority: 10,
                discountAmount: '20.00',
                label: 'High priority exclusive',
                referenceId: 'promo-b',
                appliesTo: 'transaction',
            ),
        ];

        $result = $this->service->resolve($candidates, '100.00');

        // Lower priority number = higher priority
        $this->assertSame('20.000', $result->totalTransactionDiscount);
        $this->assertSame('High priority exclusive', $result->lines[0]->label);
    }

    public function test_different_groups_combine(): void
    {
        $candidates = [
            new DiscountLine(
                source: 'manual',
                stackingGroup: 'manual',
                isExclusive: false,
                priority: 100,
                discountAmount: '10.00',
                label: 'Manual discount',
                referenceId: null,
                appliesTo: 'transaction',
            ),
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo',
                isExclusive: false,
                priority: 10,
                discountAmount: '5.00',
                label: 'Happy hour',
                referenceId: 'promo-1',
                appliesTo: 'transaction',
            ),
            new DiscountLine(
                source: 'loyalty',
                stackingGroup: 'loyalty',
                isExclusive: false,
                priority: 50,
                discountAmount: '3.00',
                label: 'Loyalty reward',
                referenceId: 'reward-1',
                appliesTo: 'transaction',
            ),
        ];

        $result = $this->service->resolve($candidates, '100.00');

        // 10 + 5 + 3 = 18
        $this->assertSame('18.000', $result->totalTransactionDiscount);
        $this->assertCount(3, $result->lines);
    }

    public function test_total_capped_at_subtotal(): void
    {
        $candidates = [
            new DiscountLine(
                source: 'manual',
                stackingGroup: 'manual',
                isExclusive: false,
                priority: 100,
                discountAmount: '80.00',
                label: 'Big manual',
                referenceId: null,
                appliesTo: 'transaction',
            ),
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo',
                isExclusive: false,
                priority: 10,
                discountAmount: '40.00',
                label: 'Big promo',
                referenceId: 'promo-1',
                appliesTo: 'transaction',
            ),
        ];

        // 80 + 40 = 120, but subtotal is 100
        $result = $this->service->resolve($candidates, '100.00');

        // Should be scaled down proportionally: 100/120 ratio
        $total = $result->totalDiscount();
        $this->assertTrue(
            bccomp($total, '100.00', 2) <= 0,
            "Total discount {$total} should not exceed subtotal 100.00"
        );
    }

    public function test_line_level_discounts_tracked_separately(): void
    {
        $candidates = [
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo',
                isExclusive: false,
                priority: 10,
                discountAmount: '5.00',
                label: 'Line promo',
                referenceId: 'promo-1',
                appliesTo: 'line:product-123',
            ),
            new DiscountLine(
                source: 'manual',
                stackingGroup: 'manual',
                isExclusive: false,
                priority: 100,
                discountAmount: '10.00',
                label: 'Transaction manual',
                referenceId: null,
                appliesTo: 'transaction',
            ),
        ];

        $result = $this->service->resolve($candidates, '100.00');

        $this->assertSame('10.000', $result->totalTransactionDiscount);
        $this->assertArrayHasKey('product-123', $result->lineDiscounts);
        $this->assertSame('5.000', $result->lineDiscounts['product-123']);
        $this->assertSame('15.000', $result->totalDiscount());
    }

    public function test_multiple_line_discounts_for_same_product_stack(): void
    {
        $candidates = [
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo-a',
                isExclusive: false,
                priority: 10,
                discountAmount: '3.00',
                label: 'Promo A',
                referenceId: 'promo-a',
                appliesTo: 'line:product-123',
            ),
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo-b',
                isExclusive: false,
                priority: 20,
                discountAmount: '2.00',
                label: 'Promo B',
                referenceId: 'promo-b',
                appliesTo: 'line:product-123',
            ),
        ];

        $result = $this->service->resolve($candidates, '100.00');

        $this->assertSame('0.00', $result->totalTransactionDiscount);
        $this->assertSame('5.000', $result->lineDiscounts['product-123']);
    }

    public function test_proportional_scaling_when_exceeding_subtotal(): void
    {
        $candidates = [
            new DiscountLine(
                source: 'manual',
                stackingGroup: 'manual',
                isExclusive: false,
                priority: 100,
                discountAmount: '60.00',
                label: 'Manual',
                referenceId: null,
                appliesTo: 'transaction',
            ),
            new DiscountLine(
                source: 'promotion',
                stackingGroup: 'promo',
                isExclusive: false,
                priority: 10,
                discountAmount: '40.00',
                label: 'Line promo',
                referenceId: 'promo-1',
                appliesTo: 'line:product-456',
            ),
        ];

        // 60 + 40 = 100, subtotal = 50 → ratio = 50/100 = 0.5
        $result = $this->service->resolve($candidates, '50.00');

        // Transaction: 60 * 0.5 = 30, Line: 40 * 0.5 = 20
        $this->assertSame('30.000', $result->totalTransactionDiscount);
        $this->assertSame('20.000', $result->lineDiscounts['product-456']);
        $this->assertSame('50.000', $result->totalDiscount());
    }

    public function test_to_array_includes_all_fields(): void
    {
        $candidates = [
            new DiscountLine(
                source: 'manual',
                stackingGroup: 'manual',
                isExclusive: false,
                priority: 100,
                discountAmount: '10.00',
                label: 'Manual discount',
                referenceId: null,
                appliesTo: 'transaction',
            ),
        ];

        $result = $this->service->resolve($candidates, '100.00');
        $array = $result->toArray();

        $this->assertArrayHasKey('lines', $array);
        $this->assertArrayHasKey('total_transaction_discount', $array);
        $this->assertArrayHasKey('line_discounts', $array);

        $this->assertCount(1, $array['lines']);
        $this->assertSame('manual', $array['lines'][0]['source']);
        $this->assertSame('10.00', $array['lines'][0]['discount_amount']);
        $this->assertSame('10.000', $array['total_transaction_discount']);
    }
}
