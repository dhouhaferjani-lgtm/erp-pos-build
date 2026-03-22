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

class PinDataEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $managerUser;

    private User $cashierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'POS Test',
            'slug' => 'pos-pin-test',
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

        $this->managerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager',
            'email' => 'manager@pos-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
            'pos_pin' => Hash::make('1234'),
            'can_discount' => true,
            'max_discount_percent' => 50.0,
        ]);
        $this->managerUser->assignRole('manager');

        UserCompanyMembership::create([
            'user_id' => $this->managerUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->cashierUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier',
            'email' => 'cashier@pos-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
            'pos_pin' => Hash::make('5678'),
            'can_discount' => false,
            'max_discount_percent' => null,
        ]);
        $this->cashierUser->assignRole('cashier');

        UserCompanyMembership::create([
            'user_id' => $this->cashierUser->id,
            'company_id' => $this->company->id,
            'role' => 'member',
        ]);
    }

    public function test_pin_data_returns_all_operators_with_pins(): void
    {
        $response = $this->actingAs($this->managerUser)
            ->getJson('/api/v1/pos/auth/pin-data');

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $operators = $response->json('data');
        $names = array_column($operators, 'name');
        $this->assertContains('Manager', $names);
        $this->assertContains('Cashier', $names);

        // Verify structure
        $operator = collect($operators)->firstWhere('name', 'Manager');
        $this->assertArrayHasKey('id', $operator);
        $this->assertArrayHasKey('name', $operator);
        $this->assertArrayHasKey('email', $operator);
        $this->assertArrayHasKey('pin_hash', $operator);
        $this->assertArrayHasKey('roles', $operator);
        $this->assertArrayHasKey('permissions', $operator);
        $this->assertArrayHasKey('can_discount', $operator);
        $this->assertArrayHasKey('max_discount_percent', $operator);
        $this->assertTrue($operator['can_discount']);
    }

    public function test_pin_data_excludes_users_without_pins(): void
    {
        // Create user without PIN
        $noPinUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Pin User',
            'email' => 'nopin@pos-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $noPinUser->assignRole('cashier');

        $response = $this->actingAs($this->managerUser)
            ->getJson('/api/v1/pos/auth/pin-data');

        $response->assertOk()
            ->assertJsonCount(2, 'data'); // Still only 2, not 3
    }
}
