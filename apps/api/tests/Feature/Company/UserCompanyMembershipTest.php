<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0.1.4: Tests for user_company_memberships table.
 *
 * UserCompanyMembership defines the many-to-many relationship between users and companies.
 * A user can belong to multiple companies with different roles.
 * Roles: owner, admin, manager, accountant, cashier, technician, viewer
 */
class UserCompanyMembershipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Account',
            'slug' => 'test-account',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'ACME Garage',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_membership_can_be_created_with_required_fields(): void
    {
        $membership = UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
        ]);

        $this->assertDatabaseHas('user_company_memberships', [
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'owner',
        ]);

        $this->assertNotNull($membership->id);
        $this->assertEquals(36, strlen($membership->id)); // UUID length
    }

    public function test_membership_belongs_to_user(): void
    {
        $membership = UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        $this->assertEquals($this->user->id, $membership->user->id);
    }

    public function test_membership_belongs_to_company(): void
    {
        $membership = UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
        ]);

        $this->assertEquals($this->company->id, $membership->company->id);
    }

    public function test_user_can_have_multiple_company_memberships(): void
    {
        $company2 = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Second Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $company2->id,
            'role' => MembershipRole::Admin,
        ]);

        $this->assertCount(2, $this->user->fresh()->companyMemberships);
    }

    public function test_company_can_have_multiple_user_memberships(): void
    {
        $user2 = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => bcrypt('password'),
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user2->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Technician,
        ]);

        $this->assertCount(2, $this->company->fresh()->memberships);
    }

    public function test_membership_unique_per_user_company(): void
    {
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
        ]);

        $this->expectException(QueryException::class);

        // Try to create another membership for same user/company
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);
    }

    public function test_membership_role_enum(): void
    {
        $owner = UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
        ]);

        $this->assertEquals(MembershipRole::Owner, $owner->role);
        $this->assertTrue($owner->isOwner());
        $this->assertTrue($owner->isAdmin());
    }

    public function test_membership_status_enum(): void
    {
        $membership = UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
            'status' => MembershipStatus::Pending,
        ]);

        $this->assertEquals(MembershipStatus::Pending, $membership->status);
    }

    public function test_membership_has_default_values(): void
    {
        $membership = UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        $membership->refresh();

        $this->assertFalse($membership->is_primary);
        $this->assertEquals(MembershipStatus::Active, $membership->status);
    }

    public function test_membership_has_invitation_tracking(): void
    {
        $inviter = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);

        $membership = UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Technician,
            'invited_by' => $inviter->id,
            'invited_at' => now(),
            'status' => MembershipStatus::Pending,
        ]);

        $this->assertEquals($inviter->id, $membership->invitedBy->id);
        $this->assertNotNull($membership->invited_at);
    }

    public function test_membership_can_have_location_restrictions(): void
    {
        $location1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Shop A',
            'type' => 'shop',
        ]);

        $location2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Shop B',
            'type' => 'shop',
        ]);

        $membership = UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Cashier,
            'allowed_location_ids' => [$location1->id],
        ]);

        $this->assertTrue($membership->canAccessLocation($location1));
        $this->assertFalse($membership->canAccessLocation($location2));
    }

    public function test_membership_without_location_restriction_can_access_all(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Shop',
            'type' => 'shop',
        ]);

        $membership = UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
            'allowed_location_ids' => null,
        ]);

        $this->assertTrue($membership->canAccessLocation($location));
    }

    public function test_primary_membership_scope(): void
    {
        $company2 = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Second Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $company2->id,
            'role' => MembershipRole::Admin,
            'is_primary' => false,
        ]);

        $primary = UserCompanyMembership::where('user_id', $this->user->id)
            ->where('is_primary', true)
            ->first();

        $this->assertEquals($this->company->id, $primary->company_id);
    }
}
