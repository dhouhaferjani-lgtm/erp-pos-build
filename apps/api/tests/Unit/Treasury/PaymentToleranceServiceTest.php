<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentToleranceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentToleranceService $service;

    private Company $company;

    private Country $country;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PaymentToleranceService::class);

        // Create test tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'domain' => 'test',
        ]);

        // Create test country
        $this->country = Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        // Create country payment settings
        CountryPaymentSettings::create([
            'country_code' => 'TN',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.100',
        ]);

        // Create test company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'default_target_margin' => '0.20',
        ]);
    }

    /** @test */
    public function it_returns_country_default_tolerance_settings_when_company_has_no_override(): void
    {
        $settings = $this->service->getToleranceSettings($this->company->id);

        $this->assertTrue($settings['enabled']);
        $this->assertEquals('0.0050', $settings['percentage']);
        $this->assertEquals('0.1000', $settings['max_amount']);
        $this->assertEquals('country', $settings['source']);
    }

    /** @test */
    public function it_returns_company_override_when_set(): void
    {
        $this->company->update([
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0100',
            'max_payment_tolerance_amount' => '0.200',
        ]);

        $settings = $this->service->getToleranceSettings($this->company->id);

        $this->assertTrue($settings['enabled']);
        $this->assertEquals('0.0100', $settings['percentage']);
        $this->assertEquals('0.2000', $settings['max_amount']);
        $this->assertEquals('company', $settings['source']);
    }

    /** @test */
    public function it_uses_system_defaults_when_no_country_or_company_settings(): void
    {
        // Remove country settings
        CountryPaymentSettings::where('country_code', 'TN')->delete();

        $settings = $this->service->getToleranceSettings($this->company->id);

        $this->assertTrue($settings['enabled']);
        $this->assertEquals('0.0050', $settings['percentage']);
        $this->assertEquals('0.5000', $settings['max_amount']);
        $this->assertEquals('system_default', $settings['source']);
    }

    /** @test */
    public function it_qualifies_small_underpayment_within_tolerance(): void
    {
        // 100.00 invoice, 99.95 payment = 0.05 underpayment (0.05%)
        $result = $this->service->checkTolerance(
            invoiceAmount: '100.0000',
            paymentAmount: '99.9500',
            companyId: $this->company->id
        );

        $this->assertTrue($result['qualifies']);
        $this->assertEquals('0.0500', $result['difference']);
        $this->assertEquals('underpayment', $result['type']);
        $this->assertNull($result['reason']);
    }

    /** @test */
    public function it_qualifies_small_overpayment_within_tolerance(): void
    {
        // 100.00 invoice, 100.05 payment = 0.05 overpayment (0.05%)
        $result = $this->service->checkTolerance(
            invoiceAmount: '100.0000',
            paymentAmount: '100.0500',
            companyId: $this->company->id
        );

        $this->assertTrue($result['qualifies']);
        $this->assertEquals('0.0500', $result['difference']);
        $this->assertEquals('overpayment', $result['type']);
        $this->assertNull($result['reason']);
    }

    /** @test */
    public function it_rejects_underpayment_exceeding_percentage_threshold(): void
    {
        // 100.00 invoice, 99.00 payment = 1.00 underpayment (1%) > 0.5% threshold
        $result = $this->service->checkTolerance(
            invoiceAmount: '100.0000',
            paymentAmount: '99.0000',
            companyId: $this->company->id
        );

        $this->assertFalse($result['qualifies']);
        $this->assertEquals('1.0000', $result['difference']);
        $this->assertEquals('underpayment', $result['type']);
        $this->assertStringContainsString('percentage threshold', $result['reason']);
    }

    /** @test */
    public function it_rejects_underpayment_exceeding_max_amount_threshold(): void
    {
        // 1000.00 invoice, 999.80 payment = 0.20 underpayment (0.02%) but > 0.10 TND max
        $result = $this->service->checkTolerance(
            invoiceAmount: '1000.0000',
            paymentAmount: '999.8000',
            companyId: $this->company->id
        );

        $this->assertFalse($result['qualifies']);
        $this->assertEquals('0.2000', $result['difference']);
        $this->assertEquals('underpayment', $result['type']);
        $this->assertStringContainsString('max amount threshold', $result['reason']);
    }

    /** @test */
    public function it_rejects_tolerance_when_disabled(): void
    {
        $this->company->update(['payment_tolerance_enabled' => false]);

        $result = $this->service->checkTolerance(
            invoiceAmount: '100.0000',
            paymentAmount: '99.9500',
            companyId: $this->company->id
        );

        $this->assertFalse($result['qualifies']);
        $this->assertEquals('Tolerance disabled', $result['reason']);
    }

    /** @test */
    public function it_qualifies_payment_at_exact_percentage_boundary(): void
    {
        // Set higher max amount to purely test percentage boundary
        $this->company->update(['max_payment_tolerance_amount' => '1.0000']);

        // 100.00 invoice, 99.50 payment = 0.50 underpayment (exactly 0.5%)
        $result = $this->service->checkTolerance(
            invoiceAmount: '100.0000',
            paymentAmount: '99.5000',
            companyId: $this->company->id
        );

        $this->assertTrue($result['qualifies']);
        $this->assertEquals('0.5000', $result['difference']);
        $this->assertEquals('underpayment', $result['type']);
    }

    /** @test */
    public function it_qualifies_payment_at_exact_max_amount_boundary(): void
    {
        // Invoice where 0.5% = 0.10 TND (at the boundary)
        // 20.00 invoice, 19.90 payment = 0.10 underpayment (0.5% AND 0.10 TND)
        $result = $this->service->checkTolerance(
            invoiceAmount: '20.0000',
            paymentAmount: '19.9000',
            companyId: $this->company->id
        );

        $this->assertTrue($result['qualifies']);
        $this->assertEquals('0.1000', $result['difference']);
        $this->assertEquals('underpayment', $result['type']);
    }

    /** @test */
    public function it_returns_zero_difference_for_exact_payment(): void
    {
        $result = $this->service->checkTolerance(
            invoiceAmount: '100.0000',
            paymentAmount: '100.0000',
            companyId: $this->company->id
        );

        $this->assertFalse($result['qualifies']);
        $this->assertEquals('0.0000', $result['difference']);
    }
}
