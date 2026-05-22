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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for POST /api/v1/pos/verify-manager-pin (Task 24).
 *
 * Covers:
 * 1. Valid PIN + valid manager → 200 with data.valid=true and user_name set.
 * 2. Wrong PIN → 200 with data.valid=false.
 * 3. Manager without pos.close_shift_with_variance → 200 with data.valid=false (no info leak).
 * 4. Rate limit — 4th attempt within 30 s → 429.
 * 5. Cross-tenant manager → 200 with data.valid=false.
 */
final class ManagerPinControllerTest extends TestCase
{
    use RefreshDatabase;

    private const VARIANCE_PERMISSION = 'pos.close_shift_with_variance';

    private const PIN = '9876';

    private const TERMINAL_ID = '33333333-3333-4333-8333-333333333333';

    private const TARGET_REFERENCE_ID = '44444444-4444-4444-8444-444444444444';

    private Tenant $tenant;

    private Company $company;

    /** Cashier making the request */
    private User $cashier;

    /** Manager with the variance permission and a known PIN */
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Manager Pin Test Tenant',
            'slug' => 'mgr-pin-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Shop',
            'legal_name' => 'Test Shop LLC',
            'tax_id' => 'TAX999',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        // Create the permissions needed by the controller.
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        Permission::findOrCreate(self::VARIANCE_PERMISSION, 'sanctum');

        // Cashier — authenticated caller.
        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier Alice',
            'email' => 'cashier@mgr-pin-test.local',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->cashier->givePermissionTo('pos.operate_terminal');

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        // Manager — holds the variance permission and has a PIN.
        $this->manager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager Bob',
            'email' => 'manager@mgr-pin-test.local',
            'password' => bcrypt('password'),
            'pos_pin' => Hash::make(self::PIN),
            'status' => UserStatus::Active,
        ]);
        $this->manager->givePermissionTo(self::VARIANCE_PERMISSION);

        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);

        // Clear any leftover rate-limiter state between tests.
        RateLimiter::clear('verify-manager-pin:127.0.0.1:'.$this->manager->id);
    }

    /**
     * Test 1: Valid PIN + valid manager → 200 with data.valid=true and user_name populated.
     */
    public function test_valid_pin_returns_valid_true_with_user_name(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/pos/verify-manager-pin', $this->approvalPayload([
                'user_id' => $this->manager->id,
                'pin' => self::PIN,
            ]));

        $response->assertOk();
        $response->assertJsonPath('data.valid', true);
        $response->assertJsonPath('data.user_id', $this->manager->id);
        $response->assertJsonPath('data.user_name', 'Manager Bob');
    }

    /**
     * Test 2: Wrong PIN → 200 with data.valid=false (and user_name null).
     */
    public function test_wrong_pin_returns_valid_false(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/pos/verify-manager-pin', $this->approvalPayload([
                'user_id' => $this->manager->id,
                'pin' => '0000',
            ]));

        $response->assertOk();
        $response->assertJsonPath('data.valid', false);
        $response->assertJsonPath('data.user_name', null);
    }

    /**
     * Test 3: Manager without pos.close_shift_with_variance → 200 with data.valid=false.
     * Should NOT return 403 — same shape to avoid info leak.
     */
    public function test_manager_without_permission_returns_valid_false(): void
    {
        $noPermUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Junior Manager',
            'email' => 'junior@mgr-pin-test.local',
            'password' => bcrypt('password'),
            'pos_pin' => Hash::make(self::PIN),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $noPermUser->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/pos/verify-manager-pin', $this->approvalPayload([
                'user_id' => $noPermUser->id,
                'pin' => self::PIN,
            ]));

        $response->assertOk();
        $response->assertJsonPath('data.valid', false);
    }

    /**
     * Test 4: 4th attempt within 30 s → 429 TOO_MANY_ATTEMPTS.
     */
    public function test_rate_limit_blocks_fourth_attempt(): void
    {
        $payload = [
            'company_id' => $this->company->id,
            'terminal_id' => self::TERMINAL_ID,
            'user_id' => $this->manager->id,
            'pin' => '0000',
            'approval_scope' => 'close_shift_variance',
            'target_event_type' => 'Z_REPORT',
            'target_reference_id' => self::TARGET_REFERENCE_ID,
            'reason' => 'Variance approval',
        ];

        // First 3 attempts are allowed (wrong PIN, but still processed).
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->cashier, 'sanctum')
                ->postJson('/api/v1/pos/verify-manager-pin', $payload);
        }

        // 4th attempt must be rate-limited.
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/pos/verify-manager-pin', $payload);

        $response->assertStatus(429);
        $response->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
    }

    /**
     * Test 5: Cross-tenant manager → 422 (validator-tier denial).
     *
     * Post api.pos-stabilization.031 fix: VerifyManagerPinRequest now scopes
     * user_id via ScopedExists::tenant on users, so a cross-tenant
     * manager_user_id is rejected at the validator BEFORE reaching
     * ManagerPinController::verify (which previously returned 200 with
     * data.valid=false from the look-up-and-fail path). The new contract
     * is more secure: cross-tenant ids never even reach the PIN comparison.
     */
    public function test_cross_tenant_manager_returns_422(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant-mgr',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $crossTenantManager = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Cross Tenant Manager',
            'email' => 'cross@other-tenant.local',
            'password' => bcrypt('password'),
            'pos_pin' => Hash::make(self::PIN),
            'status' => UserStatus::Active,
        ]);

        // Give the permission on the other tenant's scope.
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
        Permission::findOrCreate(self::VARIANCE_PERMISSION, 'sanctum');
        $crossTenantManager->givePermissionTo(self::VARIANCE_PERMISSION);

        // Restore original tenant scope for the request.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/pos/verify-manager-pin', $this->approvalPayload([
                'user_id' => $crossTenantManager->id,
                'pin' => self::PIN,
            ]));

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('user_id', $errors);
    }

    public function test_approval_verification_requires_target_context(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/pos/verify-manager-pin', [
                'user_id' => $this->manager->id,
                'pin' => self::PIN,
            ]);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('company_id', $errors);
        $this->assertArrayHasKey('terminal_id', $errors);
        $this->assertArrayHasKey('approval_scope', $errors);
        $this->assertArrayHasKey('target_event_type', $errors);
        $this->assertArrayHasKey('target_reference_id', $errors);
        $this->assertArrayHasKey('reason', $errors);
    }

    public function test_same_tenant_different_company_manager_returns_scope_mismatch_before_pin_check(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Shop',
            'legal_name' => 'Other Shop LLC',
            'tax_id' => 'TAX888',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        $scopedManager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company Manager',
            'email' => 'other-manager@mgr-pin-test.local',
            'password' => bcrypt('password'),
            'pos_pin' => Hash::make(self::PIN),
            'status' => UserStatus::Active,
        ]);
        $scopedManager->givePermissionTo(self::VARIANCE_PERMISSION);

        UserCompanyMembership::create([
            'user_id' => $scopedManager->id,
            'company_id' => $otherCompany->id,
            'role' => 'manager',
        ]);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/pos/verify-manager-pin', $this->approvalPayload([
                'user_id' => $scopedManager->id,
                'pin' => self::PIN,
            ]));

        $response->assertOk();
        $response->assertJsonPath('data.valid', false);
        $response->assertJsonPath('data.failure_code', 'scope_mismatch');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function approvalPayload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'terminal_id' => self::TERMINAL_ID,
            'user_id' => $this->manager->id,
            'pin' => self::PIN,
            'approval_scope' => 'close_shift_variance',
            'target_event_type' => 'Z_REPORT',
            'target_reference_id' => self::TARGET_REFERENCE_ID,
            'reason' => 'Variance approval',
        ], $overrides);
    }
}
