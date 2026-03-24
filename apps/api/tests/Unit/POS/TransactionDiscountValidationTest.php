<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Exceptions\DiscountExceedsLimitException;
use App\Modules\POS\Domain\Exceptions\DiscountNotAllowedException;
use App\Modules\POS\Domain\Services\DiscountCalculationService;
use App\Modules\POS\Domain\Terminal;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

/**
 * Unit tests for transaction-level discount validation.
 *
 * Tests the DiscountCalculationService::validateTransactionDiscount method
 * which validates fixed-amount discounts against percentage limits.
 */
class TransactionDiscountValidationTest extends TestCase
{
    use WithCurrencyScale;

    private DiscountCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DiscountCalculationService($this->mockCurrencyScale(3));
    }

    public function test_validates_transaction_discount_successfully(): void
    {
        $terminal = new Terminal([
            'allow_transaction_discounts' => true,
            'max_discount_percent' => 15.00,
        ]);

        $cashier = new User([
            'can_discount' => true,
            'max_discount_percent' => 20.00,
        ]);

        // 10% discount on 100 subtotal
        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '10.000',
            reason: null
        );

        // If no exception thrown, test passes
        $this->assertTrue(true);
    }

    public function test_rejects_transaction_discount_when_cashier_not_authorized(): void
    {
        $terminal = new Terminal([
            'allow_transaction_discounts' => true,
            'max_discount_percent' => 15.00,
        ]);

        $cashier = new User([
            'id' => 'test-cashier-id',
            'can_discount' => false, // No discount permission
        ]);

        $this->expectException(DiscountNotAllowedException::class);
        $this->expectExceptionMessage('not authorized to apply discounts');

        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '5.000',
            reason: null
        );
    }

    public function test_rejects_transaction_discount_when_disabled_on_terminal(): void
    {
        $terminal = new Terminal([
            'id' => 'test-terminal-id',
            'allow_transaction_discounts' => false, // Disabled
            'max_discount_percent' => 15.00,
        ]);

        $cashier = new User([
            'can_discount' => true,
            'max_discount_percent' => 20.00,
        ]);

        $this->expectException(DiscountNotAllowedException::class);
        $this->expectExceptionMessage('Transaction-level discounts are disabled on terminal');

        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '5.000',
            reason: null
        );
    }

    public function test_rejects_discount_exceeding_terminal_limit(): void
    {
        $terminal = new Terminal([
            'allow_transaction_discounts' => true,
            'max_discount_percent' => 10.00, // Terminal limit 10%
        ]);

        $cashier = new User([
            'can_discount' => true,
            'max_discount_percent' => 25.00, // Cashier has higher limit
        ]);

        $this->expectException(DiscountExceedsLimitException::class);

        // 15% discount exceeds terminal's 10% limit
        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '15.000',
            reason: null
        );
    }

    public function test_rejects_discount_exceeding_cashier_limit(): void
    {
        $terminal = new Terminal([
            'allow_transaction_discounts' => true,
            'max_discount_percent' => 20.00, // Terminal has higher limit
        ]);

        $cashier = new User([
            'can_discount' => true,
            'max_discount_percent' => 10.00, // Cashier limit 10%
        ]);

        $this->expectException(DiscountExceedsLimitException::class);

        // 15% discount exceeds cashier's 10% limit
        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '15.000',
            reason: null
        );
    }

    public function test_requires_reason_for_discounts_above_10_percent(): void
    {
        $terminal = new Terminal([
            'allow_transaction_discounts' => true,
            'max_discount_percent' => 20.00,
        ]);

        $cashier = new User([
            'can_discount' => true,
            'max_discount_percent' => 25.00,
        ]);

        $this->expectException(DiscountNotAllowedException::class);
        $this->expectExceptionMessage('Discount reason is required');

        // 15% discount without reason
        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '15.000',
            reason: null // Missing reason
        );
    }

    public function test_accepts_discount_above_10_percent_with_reason(): void
    {
        $terminal = new Terminal([
            'allow_transaction_discounts' => true,
            'max_discount_percent' => 20.00,
        ]);

        $cashier = new User([
            'can_discount' => true,
            'max_discount_percent' => 25.00,
        ]);

        // 15% discount with reason
        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '15.000',
            reason: 'VIP customer - loyalty reward'
        );

        // If no exception thrown, test passes
        $this->assertTrue(true);
    }

    public function test_rejects_discount_exceeding_subtotal(): void
    {
        $terminal = new Terminal([
            'allow_transaction_discounts' => true,
            'max_discount_percent' => 50.00,
        ]);

        $cashier = new User([
            'can_discount' => true,
            'max_discount_percent' => 50.00,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed subtotal');

        // Discount amount 150 exceeds subtotal 100
        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '150.000',
            reason: null
        );
    }

    public function test_uses_most_restrictive_limit(): void
    {
        $terminal = new Terminal([
            'allow_transaction_discounts' => true,
            'max_discount_percent' => 15.00, // More restrictive
        ]);

        $cashier = new User([
            'can_discount' => true,
            'max_discount_percent' => 25.00,
        ]);

        // 14% should pass (under terminal's 15% limit)
        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '14.000',
            reason: 'Test'
        );

        $this->assertTrue(true);

        // But 16% should fail (exceeds terminal's 15% limit)
        $this->expectException(DiscountExceedsLimitException::class);

        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '16.000',
            reason: 'Test'
        );
    }

    public function test_calculates_discount_percentage_correctly(): void
    {
        $terminal = new Terminal([
            'allow_transaction_discounts' => true,
            'max_discount_percent' => 10.00,
        ]);

        $cashier = new User([
            'can_discount' => true,
            'max_discount_percent' => 15.00,
        ]);

        // 10 discount on 100 subtotal = 10%
        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '100.000',
            discountAmount: '10.000',
            reason: 'Test'
        );

        // 5 discount on 50 subtotal = 10%
        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '50.000',
            discountAmount: '5.000',
            reason: 'Test'
        );

        // 15 discount on 150 subtotal = 10%
        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '150.000',
            discountAmount: '15.000',
            reason: 'Test'
        );

        $this->assertTrue(true);
    }

    public function test_rejects_zero_or_negative_subtotal(): void
    {
        $terminal = new Terminal([
            'allow_transaction_discounts' => true,
            'max_discount_percent' => 15.00,
        ]);

        $cashier = new User([
            'can_discount' => true,
            'max_discount_percent' => 20.00,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Subtotal must be greater than zero');

        $this->service->validateTransactionDiscount(
            terminal: $terminal,
            cashier: $cashier,
            subtotal: '0.000',
            discountAmount: '5.000',
            reason: null
        );
    }
}
