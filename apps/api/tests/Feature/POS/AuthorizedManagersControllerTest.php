<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for GET /api/v1/pos/authorized-managers.
 *
 * Covers:
 * 1. Active manager with pos.close_shift_with_variance → included in response.
 * 2. Cashier without the permission → excluded from response.
 * 3. Suspended membership → excluded from response.
 * 4. Unauthenticated request → 401.
 */
final class AuthorizedManagersControllerTest extends TestCase
{
    use RefreshDatabase;

    private const VARIANCE_PERMISSION = 'pos.close_shift_with_variance';

    private Tenant $tenant;

    private Company $company;

    /** Cashier making the request */
    private User $cashier;

    /** Manager with the variance permission */
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Auth Managers Test Tenant',
            'slug' => 'auth-mgrs-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Shop',
            'legal_name' => 'Test Shop LLC',
            'tax_id' => 'TAX888',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        Permission::findOrCreate(self::VARIANCE_PERMISSION, 'sanctum');

        // Cashier — authenticated caller, no variance permission.
        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier Alice',
            'email' => 'cashier@auth-mgrs-test.local',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->cashier->givePermissionTo('pos.operate_terminal');

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
            'status' => MembershipStatus::Active,
        ]);

        // Manager — holds the variance permission.
        $this->manager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager Bob',
            'email' => 'manager@auth-mgrs-test.local',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->manager->givePermissionTo(self::VARIANCE_PERMISSION);

        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => MembershipStatus::Active,
        ]);
    }

    /**
     * Test 1: Active manager with pos.close_shift_with_variance is included.
     * Cashier (no variance permission) is excluded.
     */
    public function test_returns_users_with_close_shift_permission(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/authorized-managers');

        $response->assertOk();

        /** @var list<array{id: string, name: string}> $data */
        $data = $response->json('data');
        $ids = array_column($data, 'id');

        $this->assertContains($this->manager->id, $ids, 'Manager with variance permission must be in the list');
        $this->assertNotContains($this->cashier->id, $ids, 'Cashier without variance permission must be excluded');
    }

    /**
     * Test 2: Response shape contains only id and name — no PINs exposed.
     */
    public function test_response_exposes_only_id_and_name(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/authorized-managers');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $this->manager->id, 'name' => 'Manager Bob']);

        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');
        $managerEntry = null;

        foreach ($data as $entry) {
            if ($entry['id'] === $this->manager->id) {
                $managerEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($managerEntry, 'Manager entry must be present');
        $this->assertArrayNotHasKey('pos_pin', $managerEntry, 'PIN must never be exposed');
        $this->assertArrayNotHasKey('password', $managerEntry, 'Password must never be exposed');
    }

    /**
     * Test 3: User with variance permission but suspended membership is excluded.
     */
    public function test_suspended_membership_excludes_user(): void
    {
        $suspendedManager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Suspended Manager',
            'email' => 'suspended@auth-mgrs-test.local',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $suspendedManager->givePermissionTo(self::VARIANCE_PERMISSION);

        UserCompanyMembership::create([
            'user_id' => $suspendedManager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => MembershipStatus::Suspended,
        ]);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/authorized-managers');

        $response->assertOk();

        /** @var list<array{id: string, name: string}> $data */
        $data = $response->json('data');
        $ids = array_column($data, 'id');

        $this->assertNotContains($suspendedManager->id, $ids, 'Suspended membership must be excluded');
    }

    /**
     * Offboarding belt: a deactivated user account is excluded from the online
     * manager list even when the membership row is still Active — the account
     * status is an independent gate, not a second reading of the same flag.
     */
    public function test_deactivated_user_excluded_even_with_active_membership(): void
    {
        $this->manager->update(['status' => UserStatus::Inactive]);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/authorized-managers');

        $response->assertOk();

        /** @var list<array{id: string, name: string}> $data */
        $data = $response->json('data');
        $ids = array_column($data, 'id');

        $this->assertNotContains($this->manager->id, $ids, 'Deactivated account must be excluded');
    }

    /**
     * Test 4: Unauthenticated request is rejected with 401.
     */
    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/pos/authorized-managers');

        $response->assertUnauthorized();
    }
}
