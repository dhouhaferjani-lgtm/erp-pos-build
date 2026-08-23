<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
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

    private Terminal $terminal;

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
            'status' => CompanyStatus::Active,
        ]);

        $location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'type' => TerminalType::Physical,
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
            'role' => 'cashier',
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
        $this->assertSame($this->tenant->id, $operator['tenant_id']);
        $this->assertSame([$this->company->id], $operator['company_ids']);
        $this->assertArrayHasKey('terminal_ids', $operator);
        $this->assertContains('close_shift_variance', $operator['approval_scopes']);
        $this->assertArrayHasKey('approval_scope_permissions_fetched_at', $operator);
        $this->assertArrayHasKey('server_time', $operator);
        $this->assertTrue($operator['can_discount']);
    }

    public function test_pin_data_scopes_approval_terminal_to_current_company(): void
    {
        $response = $this->actingAs($this->managerUser)
            ->getJson('/api/v1/pos/auth/pin-data?terminal_id='.$this->terminal->id);

        $response->assertOk();
        $operator = collect($response->json('data'))->firstWhere('name', 'Manager');
        $this->assertSame([$this->terminal->id], $operator['terminal_ids']);
    }

    public function test_pin_data_rejects_unknown_terminal_id(): void
    {
        $response = $this->actingAs($this->managerUser)
            ->getJson('/api/v1/pos/auth/pin-data?terminal_id=33333333-3333-4333-8333-333333333333');

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('terminal_id', $errors);
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

    /**
     * F-3 (HIGH): a user whose company membership is suspended must NOT appear
     * in pin-data — they would otherwise be mirrored into the device's
     * operator_pins and could locally approve an above-hard close, exceeding the
     * online AuthorizedManagersController list (active-only).
     */
    public function test_pin_data_excludes_suspended_company_members(): void
    {
        $suspendedManager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Suspended Manager',
            'email' => 'suspended-manager@pos-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
            'pos_pin' => Hash::make('4321'),
            'can_discount' => true,
            'max_discount_percent' => 80.0,
        ]);
        $suspendedManager->assignRole('manager');

        UserCompanyMembership::create([
            'user_id' => $suspendedManager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => MembershipStatus::Suspended,
        ]);

        $response = $this->actingAs($this->managerUser)
            ->getJson('/api/v1/pos/auth/pin-data');

        $response->assertOk()
            ->assertJsonCount(2, 'data'); // Manager + Cashier only, not the suspended one.

        $names = array_column($response->json('data'), 'name');
        $this->assertNotContains('Suspended Manager', $names);
    }

    /**
     * Offboarding belt: a deactivated account is never mirrored into the
     * device's operator_pins, even if its membership row is still Active. The
     * device prune (`pruneOperatorsExcept`) then drops it from the local cache
     * on the next non-empty pull.
     */
    public function test_pin_data_excludes_deactivated_users_with_active_memberships(): void
    {
        $firedManager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fired Manager',
            'email' => 'fired-manager@pos-test.local',
            'password' => 'password123',
            'status' => UserStatus::Inactive,
            'pos_pin' => Hash::make('4242'),
            'can_discount' => true,
            'max_discount_percent' => 80.0,
        ]);
        $firedManager->assignRole('manager');

        UserCompanyMembership::create([
            'user_id' => $firedManager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => MembershipStatus::Active,
        ]);

        $response = $this->actingAs($this->managerUser)
            ->getJson('/api/v1/pos/auth/pin-data');

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $names = array_column($response->json('data'), 'name');
        $this->assertNotContains('Fired Manager', $names);
    }

    /**
     * Gate r1 F-8 — the belt is `status = Active`, NOT `status != Inactive`,
     * and that is deliberate. `pending_verification` is written at exactly one
     * place (UserController::store) for an INVITED user who has not yet
     * accepted the invitation or set a password; a PIN-only cashier (no email)
     * is flipped to Active in the same transaction, so the till-operating
     * population is Active by construction. Login refuses every non-Active
     * account (AuthController -> User::isActive()), so such a user can never
     * hold a session — but the PIN surfaces do NOT require the approver to
     * have one. Under a `!= Inactive` belt, an identity nobody has yet proven
     * control of could authorize discounts, returns and variance closes.
     * Fail-closed is the correct reading; this test pins it.
     */
    public function test_pin_data_excludes_pending_verification_users(): void
    {
        $invitedManager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Invited Manager',
            'email' => 'invited-manager@pos-test.local',
            'password' => 'password123',
            'status' => UserStatus::PendingVerification,
            'pos_pin' => Hash::make('3131'),
            'can_discount' => true,
            'max_discount_percent' => 80.0,
        ]);
        $invitedManager->assignRole('manager');

        UserCompanyMembership::create([
            'user_id' => $invitedManager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => MembershipStatus::Active,
        ]);

        $response = $this->actingAs($this->managerUser)
            ->getJson('/api/v1/pos/auth/pin-data');

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $names = array_column($response->json('data'), 'name');
        $this->assertNotContains('Invited Manager', $names);
    }

    public function test_pin_data_excludes_same_tenant_pin_users_without_company_membership(): void
    {
        $outsideCompanyUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outside Company Manager',
            'email' => 'outside-manager@pos-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
            'pos_pin' => Hash::make('9999'),
            'can_discount' => true,
            'max_discount_percent' => 80.0,
        ]);
        $outsideCompanyUser->assignRole('manager');

        $response = $this->actingAs($this->managerUser)
            ->getJson('/api/v1/pos/auth/pin-data?terminal_id='.$this->terminal->id);

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $names = array_column($response->json('data'), 'name');
        $this->assertNotContains('Outside Company Manager', $names);
    }
}
