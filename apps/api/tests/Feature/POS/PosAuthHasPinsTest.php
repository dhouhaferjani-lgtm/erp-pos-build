<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipStatus;
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

/**
 * N-5 — `has-pins` must admit exactly the population `pin-data` mirrors.
 *
 * The endpoint answers one question for the device bootstrap: "does an
 * operator PIN exist that this terminal could actually verify against?" The
 * device routes `false` to the first-time PinSetupPage and `true` to
 * PinEntryPage. A `true` that pin-data cannot back with a single row is a hard
 * lockout: the cashier is sent to the PIN prompt for an empty roster.
 *
 * These tests pin the three-surface parity (verifyPin / pinData / hasPins) at
 * the has-pins end: company scope, membership-Active belt, account-Active belt.
 */
class PosAuthHasPinsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'POS Has-Pins Test',
            'slug' => 'pos-has-pins-test',
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

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // The requesting operator: an ACTIVE member of the context company who
        // holds NO PIN yet. Every scenario below asks "is there a PIN anyone
        // could enter on this terminal?" from this user's session.
        $this->cashierUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier',
            'email' => 'cashier@has-pins-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->cashierUser->assignRole('cashier');

        UserCompanyMembership::create([
            'user_id' => $this->cashierUser->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
            'status' => MembershipStatus::Active,
        ]);
    }

    public function test_has_pins_true_for_active_member_with_pin_in_context_company(): void
    {
        $manager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager',
            'email' => 'manager@has-pins-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
            'pos_pin' => Hash::make('1234'),
        ]);
        $manager->assignRole('manager');

        UserCompanyMembership::create([
            'user_id' => $manager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => MembershipStatus::Active,
        ]);

        $this->actingAs($this->cashierUser)
            ->getJson('/api/v1/pos/auth/has-pins')
            ->assertOk()
            ->assertJsonPath('data.has_pins', true);
    }

    public function test_has_pins_false_when_the_only_pin_holder_was_offboarded(): void
    {
        // The campaign scenario: the shop's only PIN holder is fired. The
        // offboarding cascade revokes the membership AND deactivates the
        // account; pin-data then returns []. has-pins must agree.
        $firedManager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fired Manager',
            'email' => 'fired@has-pins-test.local',
            'password' => 'password123',
            'status' => UserStatus::Inactive,
            'pos_pin' => Hash::make('4242'),
        ]);
        $firedManager->assignRole('manager');

        UserCompanyMembership::create([
            'user_id' => $firedManager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => MembershipStatus::Revoked,
        ]);

        $response = $this->actingAs($this->cashierUser)
            ->getJson('/api/v1/pos/auth/has-pins');

        $response->assertOk()->assertJsonPath('data.has_pins', false);

        // Parity assertion: has-pins must never promise more than pin-data can
        // deliver. This is the invariant N-5 broke.
        $pinData = $this->actingAs($this->cashierUser)
            ->getJson('/api/v1/pos/auth/pin-data');
        $pinData->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_has_pins_false_when_the_only_pin_holder_is_deactivated_with_a_stale_active_membership(): void
    {
        // Account belt on its own: a hand-edited / pre-cascade membership row
        // still reads Active, but the account is deactivated. pin-data excludes
        // them, so has-pins must too.
        $deactivated = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deactivated Manager',
            'email' => 'deactivated@has-pins-test.local',
            'password' => 'password123',
            'status' => UserStatus::Inactive,
            'pos_pin' => Hash::make('4343'),
        ]);
        $deactivated->assignRole('manager');

        UserCompanyMembership::create([
            'user_id' => $deactivated->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => MembershipStatus::Active,
        ]);

        $this->actingAs($this->cashierUser)
            ->getJson('/api/v1/pos/auth/has-pins')
            ->assertOk()
            ->assertJsonPath('data.has_pins', false);
    }

    public function test_has_pins_false_when_the_only_pin_holder_belongs_to_another_company(): void
    {
        // Company scope: a same-tenant PIN holder whose only membership is in
        // company B is invisible to a company-A terminal on verifyPin and
        // pin-data. has-pins must not announce their PIN either.
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Shop',
            'legal_name' => 'Other Shop LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $otherCompanyManager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company Manager',
            'email' => 'other-company@has-pins-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
            'pos_pin' => Hash::make('9999'),
        ]);
        $otherCompanyManager->assignRole('manager');

        UserCompanyMembership::create([
            'user_id' => $otherCompanyManager->id,
            'company_id' => $otherCompany->id,
            'role' => 'manager',
            'status' => MembershipStatus::Active,
        ]);

        $this->actingAs($this->cashierUser)
            ->getJson('/api/v1/pos/auth/has-pins')
            ->assertOk()
            ->assertJsonPath('data.has_pins', false);
    }

    public function test_has_pins_false_when_the_only_pin_holder_has_no_membership_anywhere(): void
    {
        $orphan = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Orphan Pin Holder',
            'email' => 'orphan@has-pins-test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
            'pos_pin' => Hash::make('8888'),
        ]);
        $orphan->assignRole('manager');

        $this->actingAs($this->cashierUser)
            ->getJson('/api/v1/pos/auth/has-pins')
            ->assertOk()
            ->assertJsonPath('data.has_pins', false);
    }
}
