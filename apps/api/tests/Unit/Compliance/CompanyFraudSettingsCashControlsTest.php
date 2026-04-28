<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CompanyFraudSettingsCashControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_fields_have_correct_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        // Company creation fires CompanyCreated → EnsureFraudSettingsOnCompanyCreated,
        // so settings already exist. Fetch them and verify the schema defaults.
        /** @var CompanyFraudSettings $settings */
        $settings = CompanyFraudSettings::where('company_id', $company->id)->firstOrFail();

        $this->assertSame('1.0000', (string) $settings->cash_variance_over_soft);
        $this->assertSame('20.0000', (string) $settings->cash_variance_over_hard);
        $this->assertSame('1.0000', (string) $settings->cash_variance_under_soft);
        $this->assertSame('20.0000', (string) $settings->cash_variance_under_hard);
        $this->assertTrue($settings->require_manager_pin_above_hard);
        $this->assertSame('none', $settings->cash_variance_email_severity);
    }

    public function test_rejects_soft_greater_than_or_equal_to_hard(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints only enforced on PostgreSQL');
        }

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        // Company creation fires the listener which already created a settings row.
        // Update the existing row to trigger the CHECK constraint violation.
        $settings = CompanyFraudSettings::where('company_id', $company->id)->firstOrFail();

        $this->expectException(QueryException::class);
        $settings->update([
            'cash_variance_over_soft' => '20.0000',
            'cash_variance_over_hard' => '20.0000', // equal — CHECK should fail
        ]);
    }
}
