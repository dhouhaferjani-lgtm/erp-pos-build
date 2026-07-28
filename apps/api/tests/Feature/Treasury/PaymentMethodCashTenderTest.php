<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PaymentMethodCashTenderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cash Tender Tenant',
            'slug' => 'cash-tender-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash Tender Shop',
            'legal_name' => 'Cash Tender Shop SARL',
            'tax_id' => 'TAX-CT-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('treasury.view', 'sanctum');
        Permission::findOrCreate('treasury.manage', 'sanctum');

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Treasury Admin',
            'email' => 'admin@cash-tender-test.local',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->admin->givePermissionTo('treasury.view');
        $this->admin->givePermissionTo('treasury.manage');

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);
    }

    public function test_column_exists_with_false_default(): void
    {
        $this->assertTrue(Schema::hasColumn('payment_methods', 'is_cash_tender'));

        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 2,
        ]);

        $this->assertFalse($method->refresh()->is_cash_tender);
    }

    public function test_format_method_exposes_is_cash_tender(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_cash_tender' => true,
            'is_active' => true,
            'position' => 1,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/payment-methods');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('code', 'CASH');
        $this->assertNotNull($row);
        $this->assertArrayHasKey('is_cash_tender', $row);
        $this->assertTrue($row['is_cash_tender']);
    }

    public function test_store_rejects_cash_tender_on_non_cash_code(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'meal_voucher',
                'name' => 'Ticket Restaurant',
                'is_physical' => true,
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_uppercases_code_and_accepts_cash(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'cash',
                'name' => 'Espèces',
                'is_physical' => true,
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(201);
        $this->assertSame('CASH', $response->json('data.code'));
        $this->assertTrue($response->json('data.is_cash_tender'));
    }

    public function test_update_rejects_flipping_cash_tender_on_non_cash_method(): void
    {
        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 2,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$method->id, [
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(422);
    }

    public function test_migration_backfills_existing_cash_rows(): void
    {
        // Simulate a brownfield lowercase code by writing past the controller.
        $id = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 1,
        ])->id;

        DB::table('payment_methods')
            ->where('id', $id)
            ->update(['code' => 'cash', 'is_cash_tender' => false]);

        // Re-run the backfill statement the migration performs.
        DB::statement(
            "UPDATE payment_methods SET is_cash_tender = true, code = 'CASH' WHERE UPPER(code) = 'CASH'"
        );

        $row = DB::table('payment_methods')->where('id', $id)->first();
        $this->assertSame('CASH', $row->code);
        $this->assertTrue((bool) $row->is_cash_tender);
    }
}
