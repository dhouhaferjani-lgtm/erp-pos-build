<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Application\Services\CompanyFraudSettingsService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CompanyFraudSettingsVerticalDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private CompanyFraudSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(CompanyFraudSettingsService::class);
    }

    public function test_otospex_company_gets_blind_count_enabled(): void
    {
        $tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'currency' => 'EUR',
        ]);

        $settings = $this->service->ensureForCompany($company->id);

        $this->assertTrue($settings->require_blind_cash_count);
        $this->assertTrue($settings->require_manager_pin_above_hard);
        $this->assertSame('1.0000', (string) $settings->cash_variance_over_soft);
        $this->assertSame('20.0000', (string) $settings->cash_variance_over_hard);
    }

    /** SV-9 red by design: blind counting is ruled on for every vertical. */
    public function test_izipos_company_gets_blind_count_enabled(): void
    {
        $tenant = Tenant::factory()->create(['vertical' => Vertical::Retail]);
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'currency' => 'TND',
        ]);

        $settings = $this->service->ensureForCompany($company->id);

        $this->assertTrue($settings->require_blind_cash_count);
        $this->assertTrue($settings->require_manager_pin_above_hard);
        $this->assertSame('1.0000', (string) $settings->cash_variance_over_soft);
        $this->assertSame('20.0000', (string) $settings->cash_variance_over_hard);
        // TND uses 3-decimal native; storage is 4-decimal accommodating both.
    }

    public function test_ensure_for_company_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $first = $this->service->ensureForCompany($company->id);
        $second = $this->service->ensureForCompany($company->id);

        $this->assertSame($first->id, $second->id);
    }
}
