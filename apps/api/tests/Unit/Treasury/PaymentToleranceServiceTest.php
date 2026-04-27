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
}
