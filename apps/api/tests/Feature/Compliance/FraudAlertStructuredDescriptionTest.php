<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\FraudAlert;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FraudAlertStructuredDescriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fraud_alert_accepts_description_code_and_params(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $alert = FraudAlert::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'alert_type' => 'SHIFT_CLOSE_VARIANCE',
            'severity' => 'warning',
            'description' => 'legacy fallback',
            'description_code' => 'shift_variance.over_soft',
            'description_params' => [
                'amount' => '3.0000',
                'currency' => 'EUR',
                'tender_code' => 'CASH',
                'cashier_name' => 'Amine',
            ],
            'detected_at' => now(),
            'status' => 'open',
        ]);

        $this->assertSame('shift_variance.over_soft', $alert->description_code);
        $this->assertSame('3.0000', $alert->description_params['amount']);
        $this->assertSame('EUR', $alert->fresh()->description_params['currency']);
    }

    public function test_unique_index_prevents_duplicate_shift_variance_alerts_per_z_report(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('JSONB expression unique index is PostgreSQL-only.');
        }

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $zReportId = (string) Str::uuid();

        $make = fn () => FraudAlert::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'alert_type' => 'SHIFT_CLOSE_VARIANCE',
            'severity' => 'warning',
            'description' => '',
            'detected_at' => now(),
            'status' => 'open',
            'metadata' => ['z_report_id' => $zReportId],
        ]);

        $make();
        $this->expectException(UniqueConstraintViolationException::class);
        $make();
    }
}
