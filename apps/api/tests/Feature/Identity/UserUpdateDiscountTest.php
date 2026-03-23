<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserUpdateDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $targetUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-discount',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@discount-test.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->targetUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier User',
            'email' => 'cashier@discount-test.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
            'can_discount' => false,
            'max_discount_percent' => null,
        ]);
        $this->targetUser->assignRole('cashier');
    }

    public function test_update_user_discount_permissions(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'can_discount' => true,
                'max_discount_percent' => 25.00,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.canDiscount', true);

        $responseData = $response->json('data');
        $this->assertEquals(25.0, $responseData['maxDiscountPercent']);

        $this->targetUser->refresh();
        $this->assertTrue($this->targetUser->can_discount);
        $this->assertEquals(25.0, $this->targetUser->max_discount_percent);
    }

    public function test_update_user_can_clear_discount_percent(): void
    {
        $this->targetUser->update([
            'can_discount' => true,
            'max_discount_percent' => 50.0,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'max_discount_percent' => null,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.maxDiscountPercent', null);

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'max_discount_percent' => null,
        ]);
    }

    public function test_update_user_rejects_invalid_discount_percent(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'max_discount_percent' => 150.0,
            ]);

        $response->assertUnprocessable();
    }
}
