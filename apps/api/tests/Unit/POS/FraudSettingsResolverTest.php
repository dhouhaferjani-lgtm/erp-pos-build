<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\POS\Application\DTOs\FraudSettingsDTO;
use App\Modules\POS\Application\Services\FraudSettingsResolver;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FraudSettingsResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_defaults_when_no_settings_row_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $resolver = app(FraudSettingsResolver::class);
        $dto = $resolver->forCompany($company->id);

        $this->assertInstanceOf(FraudSettingsDTO::class, $dto);
        $this->assertSame($company->id, $dto->companyId);
        $this->assertSame('1.0000', $dto->cashVarianceOverSoft);
        $this->assertSame('20.0000', $dto->cashVarianceOverHard);
        $this->assertFalse($dto->requireBlindCashCount);
        $this->assertTrue($dto->requireManagerPinAboveHard);
        $this->assertSame('none', $dto->cashVarianceEmailSeverity);
    }

    public function test_returns_persisted_values_when_settings_row_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        CompanyFraudSettings::updateOrCreate(
            ['company_id' => $company->id],
            [
                'abandoned_draft_threshold' => 5,
                'time_window_days' => 30,
                'alert_enabled' => true,
                'auto_trigger_counting' => false,
                'auto_restrict_access' => false,
                'cash_variance_over_soft' => '5.0000',
                'cash_variance_over_hard' => '50.0000',
                'cash_variance_under_soft' => '5.0000',
                'cash_variance_under_hard' => '50.0000',
                'require_blind_cash_count' => true,
                'require_manager_pin_above_hard' => true,
                'cash_variance_email_severity' => 'warning',
            ]
        );

        $resolver = app(FraudSettingsResolver::class);
        $dto = $resolver->forCompany($company->id);

        $this->assertSame('5.0000', $dto->cashVarianceOverSoft);
        $this->assertSame('50.0000', $dto->cashVarianceOverHard);
        $this->assertTrue($dto->requireBlindCashCount);
        $this->assertSame('warning', $dto->cashVarianceEmailSeverity);
    }

    public function test_for_location_signature_delegates_to_for_company(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $resolver = app(FraudSettingsResolver::class);
        $a = $resolver->forCompany($company->id);
        $b = $resolver->forLocation($company->id, null);
        $c = $resolver->forLocation($company->id, (string) Str::uuid());

        $this->assertEquals($a, $b);
        $this->assertEquals($a, $c);
    }
}
