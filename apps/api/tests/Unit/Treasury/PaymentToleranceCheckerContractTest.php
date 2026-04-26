<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Models\Country;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Shared\Contracts\Treasury\DTOs\ToleranceCheckResult;
use App\Shared\Contracts\Treasury\Enums\ToleranceType;
use App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract test — drives the new typed PaymentToleranceCheckerContract surface.
 *
 * Resolution: services the Treasury container binding (PaymentToleranceService).
 * Country: TN with 0.5% / 0.1000 absolute caps.
 */
final class PaymentToleranceCheckerContractTest extends TestCase
{
    use RefreshDatabase;

    private PaymentToleranceCheckerContract $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checker = $this->app->make(PaymentToleranceCheckerContract::class);

        Tenant::create([
            'name' => 'Contract Test Tenant',
            'slug' => 'contract-test-tenant',
            'domain' => 'contract-test',
        ]);

        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        CountryPaymentSettings::create([
            'country_code' => 'TN',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.100',
        ]);
    }

    public function test_returns_typed_result_for_qualifying_underpayment(): void
    {
        // 0.05 shortfall on 100.00 invoice — well within 0.5% AND 0.10 caps.
        $result = $this->checker->check(
            shortfall: '0.0500',
            invoiceTotal: '100.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
        );

        $this->assertInstanceOf(ToleranceCheckResult::class, $result);
        $this->assertTrue($result->qualifies);
        $this->assertSame('0.0500', $result->difference);
        $this->assertSame(ToleranceType::Underpayment, $result->type);
        $this->assertNull($result->reason);
    }

    public function test_zero_shortfall_does_not_qualify(): void
    {
        $result = $this->checker->check(
            shortfall: '0.0000',
            invoiceTotal: '100.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
        );

        $this->assertFalse($result->qualifies);
        $this->assertSame('0.0000', $result->difference);
        $this->assertSame(ToleranceType::None, $result->type);
    }

    public function test_rejects_when_shortfall_exceeds_percentage_threshold(): void
    {
        // 1.00 on 100.00 = 1% > 0.5% TN.
        $result = $this->checker->check(
            shortfall: '1.0000',
            invoiceTotal: '100.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
        );

        $this->assertFalse($result->qualifies);
        $this->assertSame('1.0000', $result->difference);
        $this->assertSame(ToleranceType::Underpayment, $result->type);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('percentage threshold', (string) $result->reason);
    }

    public function test_rejects_when_shortfall_exceeds_max_amount_threshold(): void
    {
        // 0.20 on 1000.00 = 0.02% (passes %), but > 0.10 TND absolute cap.
        $result = $this->checker->check(
            shortfall: '0.2000',
            invoiceTotal: '1000.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
        );

        $this->assertFalse($result->qualifies);
        $this->assertSame('0.2000', $result->difference);
        $this->assertSame(ToleranceType::Underpayment, $result->type);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('max amount threshold', (string) $result->reason);
    }

    public function test_returns_none_when_tolerance_disabled_for_country(): void
    {
        CountryPaymentSettings::where('country_code', 'TN')->update([
            'payment_tolerance_enabled' => false,
        ]);

        $result = $this->checker->check(
            shortfall: '0.0500',
            invoiceTotal: '100.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
        );

        $this->assertFalse($result->qualifies);
        $this->assertSame('0.0000', $result->difference);
        $this->assertSame(ToleranceType::None, $result->type);
        $this->assertSame('Tolerance disabled', $result->reason);
    }

    /* ------------------------------------------------------------------- */
    /* Strict-mode boundary semantics (A1 vs A2 — audit PR #42 medium) */
    /* ------------------------------------------------------------------- */

    public function test_inclusive_mode_qualifies_at_exact_max_amount_boundary(): void
    {
        // shortfall == max_amount cap (0.10) on 1000.00 invoice (0.5% = 5.00, so % gate is loose).
        $result = $this->checker->check(
            shortfall: '0.1000',
            invoiceTotal: '1000.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
            strict: false,
        );

        $this->assertTrue($result->qualifies, 'Inclusive (A1) mode admits == boundary.');
        $this->assertSame(ToleranceType::Underpayment, $result->type);
    }

    public function test_strict_mode_rejects_at_exact_max_amount_boundary(): void
    {
        $result = $this->checker->check(
            shortfall: '0.1000',
            invoiceTotal: '1000.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
            strict: true,
        );

        $this->assertFalse($result->qualifies, 'Strict (A2) mode rejects == boundary.');
        $this->assertSame(ToleranceType::Underpayment, $result->type);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('max amount', (string) $result->reason);
    }

    public function test_inclusive_mode_qualifies_at_exact_percentage_boundary(): void
    {
        // 0.5% of 12.00 = 0.06, strictly below 0.10 max so percentage is the binding gate.
        $result = $this->checker->check(
            shortfall: '0.0600',
            invoiceTotal: '12.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
            strict: false,
        );

        $this->assertTrue($result->qualifies);
    }

    public function test_strict_mode_rejects_at_exact_percentage_boundary(): void
    {
        $result = $this->checker->check(
            shortfall: '0.0600',
            invoiceTotal: '12.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
            strict: true,
        );

        $this->assertFalse($result->qualifies);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('percentage', (string) $result->reason);
    }

    public function test_strict_mode_qualifies_just_below_threshold(): void
    {
        // 0.0599 < 0.0600 percentage gate — both modes admit.
        $result = $this->checker->check(
            shortfall: '0.0599',
            invoiceTotal: '12.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
            strict: true,
        );

        $this->assertTrue($result->qualifies);
        $this->assertSame('0.0599', $result->difference);
    }

    public function test_falls_back_to_system_defaults_when_country_settings_missing(): void
    {
        CountryPaymentSettings::where('country_code', 'TN')->delete();

        // System default: 0.5% / 0.5000 absolute. 0.20 fits both.
        $result = $this->checker->check(
            shortfall: '0.2000',
            invoiceTotal: '100.0000',
            currencyCode: 'TND',
            countryCode: 'TN',
        );

        $this->assertTrue($result->qualifies);
        $this->assertSame('0.2000', $result->difference);
        $this->assertSame(ToleranceType::Underpayment, $result->type);
    }
}
