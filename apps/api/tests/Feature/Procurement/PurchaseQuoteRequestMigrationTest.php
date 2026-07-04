<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseQuoteRequestMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rfq_sequence_preseed_does_not_reset_existing_last_number(): void
    {
        $tenant = Tenant::create([
            'name' => 'RFQ Migration Tenant',
            'slug' => 'rfq-migration-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'RFQ Migration Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $year = (int) date('Y');

        DB::table('document_sequences')->updateOrInsert(
            [
                'company_id' => $company->id,
                'type' => 'purchase_rfq',
                'year' => $year,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'last_number' => 42,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $migration = require database_path('migrations/tenant/2026_07_03_200000_add_rfq_group_index.php');
        $migration->up();

        $this->assertSame(42, DB::table('document_sequences')
            ->where('company_id', $company->id)
            ->where('type', 'purchase_rfq')
            ->where('year', $year)
            ->value('last_number'));
    }
}
