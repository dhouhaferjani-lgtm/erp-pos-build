<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRevocationReason;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Offboarding cascade: deactivating (or soft-deleting) a user must revoke every
 * ACTIVE company membership that user holds, in the same transaction.
 *
 * Before this lane, `users.status = inactive` was the ONLY offboarding lever and
 * it never touched `user_company_memberships`. Every hardened authorization site
 * (CompanyContext::userHasAccessToCompany, PinVerifier::verifyForApproval,
 * PosAuthController::pinData, AuthorizedManagersController) reads ONLY the
 * membership status — so a fired manager kept an ACTIVE membership forever and
 * their POS override PIN kept approving discounts, returns and variance closes.
 *
 * Reactivation reverses exactly (and only) the cascade's own side effect:
 * memberships stamped `revoked_reason = user_deactivated` go back to Active.
 */
final class UserOffboardingCascadeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $companyA;

    private Company $companyB;

    private User $adminUser;

    private User $targetUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Offboarding Tenant',
            'slug' => 'offboarding-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = $this->makeCompany('Offboarding Co A', true);
        $this->companyB = $this->makeCompany('Offboarding Co B', false);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@offboarding.local',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->companyA->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        // The manager being fired — member of BOTH companies.
        $this->targetUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fired Manager',
            'email' => 'fired@offboarding.local',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->targetUser->assignRole('manager');

        foreach ([$this->companyA, $this->companyB] as $company) {
            UserCompanyMembership::create([
                'user_id' => $this->targetUser->id,
                'company_id' => $company->id,
                'role' => MembershipRole::Manager,
                'status' => MembershipStatus::Active,
                'accepted_at' => now(),
            ]);
        }
    }

    private function makeCompany(string $name, bool $isHeadquarters): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => $name.' SARL',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => $isHeadquarters,
        ]);
    }

    // ========== DEACTIVATE CASCADE ==========

    public function test_deactivating_a_user_revokes_every_active_membership(): void
    {
        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate")
            ->assertOk();

        $statuses = UserCompanyMembership::query()
            ->where('user_id', $this->targetUser->id)
            ->pluck('status')
            ->all();

        $this->assertCount(2, $statuses);
        foreach ($statuses as $status) {
            $this->assertSame(MembershipStatus::Revoked, $status);
        }
    }

    public function test_deactivation_stamps_revocation_provenance(): void
    {
        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate")
            ->assertOk();

        $memberships = UserCompanyMembership::query()
            ->where('user_id', $this->targetUser->id)
            ->get();

        foreach ($memberships as $membership) {
            $this->assertNotNull($membership->revoked_at);
            $this->assertSame($this->adminUser->id, $membership->revoked_by);
            $this->assertSame(
                MembershipRevocationReason::UserDeactivated,
                $membership->revoked_reason
            );
        }
    }

    public function test_deactivated_user_loses_company_access(): void
    {
        $context = app(CompanyContext::class);

        $this->assertTrue(
            $context->userHasAccessToCompany($this->targetUser, $this->companyA->id),
            'precondition: an active manager has company access'
        );

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate")
            ->assertOk();

        $this->assertFalse(
            $context->userHasAccessToCompany($this->targetUser->refresh(), $this->companyA->id)
        );
        $this->assertFalse(
            $context->userHasAccessToCompany($this->targetUser, $this->companyB->id)
        );
        $this->assertNull($context->getDefaultCompanyForUser($this->targetUser));
    }

    public function test_destroy_revokes_every_active_membership(): void
    {
        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->deleteJson("/api/v1/users/{$this->targetUser->id}")
            ->assertOk();

        $this->assertSame(
            0,
            UserCompanyMembership::query()
                ->where('user_id', $this->targetUser->id)
                ->where('status', MembershipStatus::Active->value)
                ->count()
        );
    }

    public function test_cascade_leaves_other_users_memberships_untouched(): void
    {
        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate")
            ->assertOk();

        $adminMembership = UserCompanyMembership::query()
            ->where('user_id', $this->adminUser->id)
            ->firstOrFail();

        $this->assertSame(MembershipStatus::Active, $adminMembership->status);
        $this->assertNull($adminMembership->revoked_at);
    }

    // ========== REACTIVATION EDGE ==========

    public function test_reactivation_restores_memberships_revoked_by_the_cascade(): void
    {
        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate")
            ->assertOk();

        // INTERMEDIATE state — without this the test is vacuous: if the cascade
        // never ran, the rows are already Active with NULL stamps and the
        // end-state assertions below pass against broken code (gate r1 F-4).
        $revokedMidCycle = UserCompanyMembership::query()
            ->where('user_id', $this->targetUser->id)
            ->get();

        $this->assertCount(2, $revokedMidCycle);
        foreach ($revokedMidCycle as $membership) {
            $this->assertSame(MembershipStatus::Revoked, $membership->status);
            $this->assertSame(
                MembershipRevocationReason::UserDeactivated,
                $membership->revoked_reason
            );
        }

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/activate")
            ->assertOk();

        $memberships = UserCompanyMembership::query()
            ->where('user_id', $this->targetUser->id)
            ->get();

        $this->assertCount(2, $memberships);
        foreach ($memberships as $membership) {
            $this->assertSame(MembershipStatus::Active, $membership->status);
            $this->assertNull($membership->revoked_at);
            $this->assertNull($membership->revoked_by);
            $this->assertNull($membership->revoked_reason);
        }
    }

    /**
     * A membership revoked for any reason OTHER than the deactivation cascade
     * represents an independent decision. Reactivating the ACCOUNT must not
     * silently undo it. Legacy rows (revoked before this lane, so
     * `revoked_reason IS NULL`) fall in the same fail-closed bucket.
     */
    public function test_reactivation_does_not_restore_independently_revoked_memberships(): void
    {
        UserCompanyMembership::query()
            ->where('user_id', $this->targetUser->id)
            ->where('company_id', $this->companyB->id)
            ->update([
                'status' => MembershipStatus::Revoked->value,
                'revoked_at' => now(),
                'revoked_by' => $this->adminUser->id,
                'revoked_reason' => null,
            ]);

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate")
            ->assertOk();

        // INTERMEDIATE state — the two rows must be Revoked for DIFFERENT
        // reasons at this point. Without this the end-state assertions pass
        // against a missing cascade (companyA simply never left Active).
        $this->assertSame(
            MembershipRevocationReason::UserDeactivated,
            $this->membershipFor($this->companyA)->revoked_reason
        );
        $this->assertNull($this->membershipFor($this->companyB)->revoked_reason);

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/activate")
            ->assertOk();

        $restored = $this->membershipFor($this->companyA);
        $stillRevoked = $this->membershipFor($this->companyB);

        $this->assertSame(MembershipStatus::Active, $restored->status);
        $this->assertSame(MembershipStatus::Revoked, $stillRevoked->status);
        $this->assertNull($stillRevoked->revoked_reason);
    }

    /**
     * Non-Active memberships are NOT swept by the cascade: they already grant
     * nothing (every authorization site requires Active), and leaving them alone
     * keeps the reverse edge lossless — reactivation restores exactly the rows
     * the cascade took, and a Pending invitation stays Pending.
     */
    public function test_cascade_leaves_non_active_memberships_in_place(): void
    {
        UserCompanyMembership::query()
            ->where('user_id', $this->targetUser->id)
            ->where('company_id', $this->companyB->id)
            ->update(['status' => MembershipStatus::Pending->value]);

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate")
            ->assertOk();

        // The sibling ACTIVE row must have cycled — this is what makes the
        // Pending assertion meaningful rather than vacuous (gate r1 F-4): the
        // cascade demonstrably ran on this user, and skipped only the
        // non-Active row.
        $cycled = $this->membershipFor($this->companyA);
        $this->assertSame(MembershipStatus::Revoked, $cycled->status);
        $this->assertSame(MembershipRevocationReason::UserDeactivated, $cycled->revoked_reason);

        $pending = $this->membershipFor($this->companyB);
        $this->assertSame(MembershipStatus::Pending, $pending->status);
        $this->assertNull($pending->revoked_at);
        $this->assertNull($pending->revoked_reason);

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/activate")
            ->assertOk();

        // …and through the reverse edge: the Active row comes back, the Pending
        // row is NOT flattened into Active. That losslessness is the whole
        // reason the sweep is Active-only.
        $this->assertSame(MembershipStatus::Active, $this->membershipFor($this->companyA)->status);
        $this->assertSame(MembershipStatus::Pending, $this->membershipFor($this->companyB)->status);
    }

    private function membershipFor(Company $company): UserCompanyMembership
    {
        return UserCompanyMembership::query()
            ->where('user_id', $this->targetUser->id)
            ->where('company_id', $company->id)
            ->firstOrFail();
    }
}
