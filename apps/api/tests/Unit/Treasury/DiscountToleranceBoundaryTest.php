<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\DiscountToleranceBoundary;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Exceptions\DiscountBelowToleranceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for DiscountToleranceBoundary — the anti-abuse rule that blocks
 * discounts whose magnitude is at-or-below the applicable payment-tolerance
 * margin. The semantics MUST be a strict `discount > margin`: a discount
 * exactly at the margin rejects (closes the sub-tolerance arbitrage where a
 * cashier could otherwise engineer a write-off-shaped discount).
 */
final class DiscountToleranceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private DiscountToleranceBoundary $service;

    private Tenant $tenant;

    private Country $countryFr;

    private Country $countryTn;

    private Company $companyFr;

    private Company $companyTn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DiscountToleranceBoundary::class);

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // FR — €0.50 absolute, 0.5% percentage
        $this->countryFr = Country::create([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'currency_symbol' => '€',
        ]);

        CountryPaymentSettings::create([
            'country_code' => 'FR',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.500',
        ]);

        $this->companyFr = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'FR Test Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'default_target_margin' => '0.20',
        ]);

        // TN — 0.100 TND absolute, 0.5% percentage (scale-3 parity)
        $this->countryTn = Country::create([
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

        $this->companyTn = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TN Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'default_target_margin' => '0.20',
        ]);
    }

    public function test_throws_when_discount_is_strictly_less_than_fixed_threshold(): void
    {
        // FR margin: max(€0.50 absolute, 100.00 * 0.5% = €0.50) = €0.50
        $this->expectException(DiscountBelowToleranceException::class);
        $this->service->assertDiscountAboveTolerance(
            discountAmount: '0.40',
            subtotal: '100.00',
            companyId: (string) $this->companyFr->id,
        );
    }

    public function test_throws_when_discount_equals_threshold_strict_inequality(): void
    {
        // €0.50 discount exactly equals €0.50 margin — must reject (strict >).
        $this->expectException(DiscountBelowToleranceException::class);
        $this->service->assertDiscountAboveTolerance(
            discountAmount: '0.50',
            subtotal: '100.00',
            companyId: (string) $this->companyFr->id,
        );
    }

    public function test_passes_when_discount_above_threshold(): void
    {
        $this->service->assertDiscountAboveTolerance(
            discountAmount: '0.51',
            subtotal: '100.00',
            companyId: (string) $this->companyFr->id,
        );

        $this->addToAssertionCount(1);
    }

    public function test_percentage_threshold_dominates_when_subtotal_large(): void
    {
        // FR percentage threshold: 1000.00 * 0.5% = €5.00. Absolute is €0.50.
        // max(€0.50, €5.00) = €5.00. A €4.00 discount is sub-tolerance.
        $this->expectException(DiscountBelowToleranceException::class);
        $this->service->assertDiscountAboveTolerance(
            discountAmount: '4.00',
            subtotal: '1000.00',
            companyId: (string) $this->companyFr->id,
        );
    }

    public function test_tnd_scale_3_handled_correctly(): void
    {
        // TN margin: max(0.100 TND, 10.000 * 0.5% = 0.050 TND) = 0.100 TND.
        // 0.050 TND discount is sub-tolerance.
        $this->expectException(DiscountBelowToleranceException::class);
        $this->service->assertDiscountAboveTolerance(
            discountAmount: '0.050',
            subtotal: '10.000',
            companyId: (string) $this->companyTn->id,
        );
    }

    public function test_zero_discount_does_not_throw(): void
    {
        // Zero discount means "no discount applied" — never a violation.
        $this->service->assertDiscountAboveTolerance(
            discountAmount: '0.00',
            subtotal: '100.00',
            companyId: (string) $this->companyFr->id,
        );

        $this->addToAssertionCount(1);
    }

    public function test_above_tolerance_tnd_passes(): void
    {
        // 0.150 TND > 0.100 TND margin
        $this->service->assertDiscountAboveTolerance(
            discountAmount: '0.150',
            subtotal: '10.000',
            companyId: (string) $this->companyTn->id,
        );

        $this->addToAssertionCount(1);
    }

    public function test_exception_carries_discount_margin_and_subtotal(): void
    {
        try {
            $this->service->assertDiscountAboveTolerance(
                discountAmount: '0.30',
                subtotal: '100.00',
                companyId: (string) $this->companyFr->id,
            );
            $this->fail('Expected DiscountBelowToleranceException was not thrown.');
        } catch (DiscountBelowToleranceException $e) {
            $this->assertSame('100.00', $e->subtotal);
            // Boundary internally normalises to scale 4 to match the tolerance pipeline.
            $this->assertSame(0, bccomp('0.30', $e->discountAmount, 4));
            $this->assertSame(0, bccomp('0.50', $e->toleranceMargin, 4));
        }
    }
}
