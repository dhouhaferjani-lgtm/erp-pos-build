<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Exceptions\DiscountExceedsLimitException;
use App\Modules\POS\Domain\Exceptions\DiscountNotAllowedException;
use App\Modules\POS\Domain\Services\DiscountCalculationService;
use App\Modules\POS\Domain\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

/**
 * Unit tests for DiscountCalculationService
 *
 * Tests discount validation logic including:
 * - Cashier authorization checks
 * - Terminal permission checks
 * - Limit enforcement (terminal vs cashier)
 * - Reason requirement validation
 * - Discount amount calculations
 */
class DiscountCalculationServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithCurrencyScale;

    private DiscountCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DiscountCalculationService($this->mockCurrencyScale(3));
    }

    /**
     * Test that cashier without permission cannot apply line discount
     */
    public function test_cashier_without_permission_cannot_apply_line_discount(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 20.00]);
        $cashier = $this->createCashier(['can_discount' => false]);

        $this->expectException(DiscountNotAllowedException::class);
        $this->expectExceptionMessage('not authorized to apply discounts');

        $this->service->validateLineDiscount($terminal, $cashier, 10.00);
    }

    /**
     * Test that cashier without permission cannot apply transaction discount
     */
    public function test_cashier_without_permission_cannot_apply_transaction_discount(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 20.00]);
        $cashier = $this->createCashier(['can_discount' => false]);

        $this->expectException(DiscountNotAllowedException::class);
        $this->expectExceptionMessage('not authorized to apply discounts');

        $this->service->validateTransactionDiscount($terminal, $cashier, '100.000', '10.000');
    }

    /**
     * Test that line discounts are blocked when terminal disables them
     */
    public function test_line_discounts_blocked_when_terminal_disables(): void
    {
        $terminal = $this->createTerminal(['allow_line_discounts' => false]);
        $cashier = $this->createCashier(['can_discount' => true]);

        $this->expectException(DiscountNotAllowedException::class);
        $this->expectExceptionMessage('Line-level discounts are disabled');

        $this->service->validateLineDiscount($terminal, $cashier, 5.00);
    }

    /**
     * Test that transaction discounts are blocked when terminal disables them
     */
    public function test_transaction_discounts_blocked_when_terminal_disables(): void
    {
        $terminal = $this->createTerminal(['allow_transaction_discounts' => false]);
        $cashier = $this->createCashier(['can_discount' => true]);

        $this->expectException(DiscountNotAllowedException::class);
        $this->expectExceptionMessage('Transaction-level discounts are disabled');

        $this->service->validateTransactionDiscount($terminal, $cashier, '100.000', '5.000');
    }

    /**
     * Test that discount exceeding terminal limit is rejected
     */
    public function test_discount_exceeding_terminal_limit_is_rejected(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 10.00]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => 20.00, // Higher than terminal
        ]);

        $this->expectException(DiscountExceedsLimitException::class);
        $this->expectExceptionMessage('exceeds effective limit of 10.00');

        $this->service->validateLineDiscount($terminal, $cashier, 15.00);
    }

    /**
     * Test that discount exceeding cashier limit is rejected
     */
    public function test_discount_exceeding_cashier_limit_is_rejected(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 30.00]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => 15.00, // Lower than terminal
        ]);

        $this->expectException(DiscountExceedsLimitException::class);
        $this->expectExceptionMessage('exceeds effective limit of 15.00');

        $this->service->validateLineDiscount($terminal, $cashier, 20.00);
    }

    /**
     * Test that most restrictive limit is enforced (terminal)
     */
    public function test_most_restrictive_limit_enforced_terminal(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 10.00]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => 20.00,
        ]);

        $effectiveLimit = $this->service->getEffectiveDiscountLimit($terminal, $cashier);

        $this->assertEquals(10.00, $effectiveLimit['limit']);
        $this->assertEquals('terminal', $effectiveLimit['source']);
    }

    /**
     * Test that most restrictive limit is enforced (cashier)
     */
    public function test_most_restrictive_limit_enforced_cashier(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 25.00]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => 12.00,
        ]);

        $effectiveLimit = $this->service->getEffectiveDiscountLimit($terminal, $cashier);

        $this->assertEquals(12.00, $effectiveLimit['limit']);
        $this->assertEquals('cashier', $effectiveLimit['source']);
    }

    /**
     * Test that cashier with NULL max_discount uses terminal limit
     */
    public function test_cashier_null_limit_uses_terminal_limit(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 15.00]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => null, // No individual limit
        ]);

        $effectiveLimit = $this->service->getEffectiveDiscountLimit($terminal, $cashier);

        $this->assertEquals(15.00, $effectiveLimit['limit']);
        $this->assertEquals('terminal', $effectiveLimit['source']);
    }

    /**
     * Test that discount reason is required above 10%
     */
    public function test_reason_required_for_high_discounts(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 30.00]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => 30.00,
        ]);

        $this->expectException(DiscountNotAllowedException::class);
        $this->expectExceptionMessage('Discount reason is required for discounts above 10.00%');

        $this->service->validateLineDiscount($terminal, $cashier, 15.00, null);
    }

    /**
     * Test that discount with reason above 10% is accepted
     */
    public function test_high_discount_with_reason_is_accepted(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 30.00]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => 30.00,
        ]);

        // Should not throw exception
        $this->service->validateLineDiscount($terminal, $cashier, 15.00, 'Loyal customer discount');

        $this->assertTrue(true); // Test passes if no exception thrown
    }

    /**
     * Test that empty string reason is treated as missing
     */
    public function test_empty_string_reason_treated_as_missing(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 30.00]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => 30.00,
        ]);

        $this->expectException(DiscountNotAllowedException::class);

        $this->service->validateLineDiscount($terminal, $cashier, 12.00, '   '); // Whitespace only
    }

    /**
     * Test that discount exactly at 10% doesn't require reason
     */
    public function test_discount_at_threshold_does_not_require_reason(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 30.00]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => 30.00,
        ]);

        // Should not throw exception
        $this->service->validateLineDiscount($terminal, $cashier, 10.00, null);

        $this->assertTrue(true); // Test passes if no exception thrown
    }

    /**
     * Test line discount amount calculation from percentage
     */
    public function test_calculate_line_discount_from_percentage(): void
    {
        $baseAmount = '100.00';
        $discountPercent = '15.00';

        $discountAmount = $this->service->calculateLineDiscountAmount($baseAmount, $discountPercent);

        $this->assertEquals('15.000', $discountAmount);
    }

    /**
     * Test line discount calculation with decimal base amount
     */
    public function test_calculate_discount_with_decimal_base(): void
    {
        $baseAmount = '87.50';
        $discountPercent = '10.00';

        $discountAmount = $this->service->calculateLineDiscountAmount($baseAmount, $discountPercent);

        $this->assertEquals('8.750', $discountAmount);
    }

    /**
     * Test line discount calculation rounds to 2 decimals
     */
    public function test_discount_calculation_rounds_to_two_decimals(): void
    {
        $baseAmount = '33.33';
        $discountPercent = '10.00'; // Results in 3.333

        $discountAmount = $this->service->calculateLineDiscountAmount($baseAmount, $discountPercent);

        $this->assertEquals('3.333', $discountAmount);
    }

    /**
     * Test fixed discount validation
     */
    public function test_fixed_discount_validation(): void
    {
        $baseAmount = '100.00';
        $fixedDiscount = '25.00';

        $discountAmount = $this->service->calculateFixedDiscountAmount($baseAmount, $fixedDiscount);

        $this->assertEquals('25.000', $discountAmount);
    }

    /**
     * Test that fixed discount cannot exceed base amount
     */
    public function test_fixed_discount_cannot_exceed_base_amount(): void
    {
        $baseAmount = '100.00';
        $fixedDiscount = '150.00'; // More than base

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed base amount');

        $this->service->calculateFixedDiscountAmount($baseAmount, $fixedDiscount);
    }

    /**
     * Percent arg is a numeric-string — no float coercion or drift.
     *
     * 10.05% of 100.000 = 10.050 exactly.  If the param were (float), PHP
     * strict_types would throw a TypeError; even where coercion is allowed,
     * bcdiv((string)(float)'10.05', …) can silently drift the last digit.
     */
    public function test_line_discount_accepts_numeric_string_percent_no_float(): void
    {
        $this->assertSame(
            '10.050',
            $this->service->calculateLineDiscountAmount('100.000', '10.05')
        );
    }

    /**
     * Test valid discount within all limits
     */
    public function test_valid_discount_within_all_limits(): void
    {
        $terminal = $this->createTerminal([
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
        ]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => 25.00,
        ]);

        // Should not throw exception
        $this->service->validateLineDiscount($terminal, $cashier, 15.00, 'Valid discount');

        $this->assertTrue(true); // Test passes if no exception thrown
    }

    /**
     * Test discount at exact limit is accepted
     */
    public function test_discount_at_exact_limit_is_accepted(): void
    {
        $terminal = $this->createTerminal(['max_discount_percent' => 20.00]);
        $cashier = $this->createCashier([
            'can_discount' => true,
            'max_discount_percent' => 20.00,
        ]);

        // Should not throw exception
        $this->service->validateLineDiscount($terminal, $cashier, 20.00, 'At exact limit');

        $this->assertTrue(true); // Test passes if no exception thrown
    }

    /**
     * Create a test terminal with given attributes
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createTerminal(array $attributes = []): Terminal
    {
        $defaults = [
            'tenant_id' => '00000000-0000-0000-0000-000000000001',
            'company_id' => '00000000-0000-0000-0000-000000000002',
            'location_id' => '00000000-0000-0000-0000-000000000003',
            'code' => 'POS01',
            'name' => 'Test Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 1,
            'current_year' => 2026,
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ];

        return Terminal::make(array_merge($defaults, $attributes));
    }

    /**
     * Create a test cashier with given attributes
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createCashier(array $attributes = []): User
    {
        $defaults = [
            'tenant_id' => '00000000-0000-0000-0000-000000000001',
            'name' => 'Test Cashier',
            'email' => 'cashier@test.com',
            'password' => 'password123',
            'can_discount' => true,
            'max_discount_percent' => 15.00,
        ];

        return User::make(array_merge($defaults, $attributes));
    }
}
