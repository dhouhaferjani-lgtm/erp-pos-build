<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProcurementPolicyEntryPointColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_point_columns_have_fail_closed_defaults_and_boolean_casts(): void
    {
        $this->assertTrue(Schema::hasColumns('procurement_policies', [
            'allow_receipt_first',
            'allow_invoice_first',
            'invoice_first_requires_approval',
        ]));

        $tenant = Tenant::create([
            'name' => 'Policy Columns Tenant',
            'slug' => 'policy-columns-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Policy Columns Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $policy = ProcurementPolicy::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
        ])->refresh();

        $this->assertFalse($policy->allow_receipt_first);
        $this->assertFalse($policy->allow_invoice_first);
        $this->assertTrue($policy->invoice_first_requires_approval);
    }
}
