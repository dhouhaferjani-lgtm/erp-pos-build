<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PosAuthVerifyPinTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Verify Pin Test',
            'slug' => 'verify-pin-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Shop',
            'legal_name' => 'Test Shop LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@verify-pin-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
            'pos_pin' => Hash::make('1234'),
            'can_discount' => false,
            'max_discount_percent' => 0.0,
        ]);
        $this->adminUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    public function test_verify_pin_grants_discount_to_admin_regardless_of_db(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pos/auth/verify-pin', [
                'pin' => '1234',
            ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.can_discount'));
        $this->assertEquals(100.0, $response->json('data.max_discount_percent'));
    }

    public function test_setup_pin_grants_discount_to_admin_regardless_of_db(): void
    {
        // Remove existing pin first so we can set a new one
        $this->adminUser->update(['pos_pin' => null]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pos/auth/setup-pin', [
                'pin' => '9999',
            ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.can_discount'));
        $this->assertEquals(100.0, $response->json('data.max_discount_percent'));
    }

    public function test_pin_data_grants_discount_to_admin_regardless_of_db(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/pos/auth/pin-data');

        $response->assertOk();

        $operators = $response->json('data');
        $admin = collect($operators)->firstWhere('name', 'Admin User');

        $this->assertNotNull($admin);
        $this->assertTrue($admin['can_discount']);
        $this->assertEquals(100.0, $admin['max_discount_percent']);
    }

    public function test_verify_pin_does_not_grant_discount_to_non_admin(): void
    {
        $cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier',
            'email' => 'cashier@verify-pin-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
            'pos_pin' => Hash::make('5678'),
            'can_discount' => false,
            'max_discount_percent' => 10.0,
        ]);
        $cashier->assignRole('cashier');

        UserCompanyMembership::create([
            'user_id' => $cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        $response = $this->actingAs($cashier)
            ->postJson('/api/v1/pos/auth/verify-pin', [
                'pin' => '5678',
            ]);

        $response->assertOk();
        $this->assertFalse($response->json('data.can_discount'));
        $this->assertEquals(10.0, $response->json('data.max_discount_percent'));
    }
}
