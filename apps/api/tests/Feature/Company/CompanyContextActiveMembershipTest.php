<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FU-2a — `CompanyContext` must require an ACTIVE membership.
 *
 * Both `userHasAccessToCompany` (which gates every company-scoped route via
 * `CompanyContextMiddleware`) and `getDefaultCompanyForUser` previously checked
 * membership EXISTENCE, not status. A suspended/revoked member who still held a
 * valid token therefore kept passing company context — the root of the FU-2
 * privilege-escalation finding. These tests pin the active-only contract.
 */
final class CompanyContextActiveMembershipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->context = new CompanyContext;
    }

    private function company(): Company
    {
        return Company::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function membership(Company $company, MembershipStatus $status, bool $isPrimary = false): void
    {
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'role' => 'admin',
            'status' => $status->value,
            'is_primary' => $isPrimary,
        ]);
    }

    public function test_user_has_access_to_company_is_true_for_an_active_membership(): void
    {
        $company = $this->company();
        $this->membership($company, MembershipStatus::Active);

        $this->assertTrue($this->context->userHasAccessToCompany($this->user, $company->id));
    }

    public function test_user_has_access_to_company_is_false_for_a_suspended_membership(): void
    {
        $company = $this->company();
        $this->membership($company, MembershipStatus::Suspended);

        $this->assertFalse($this->context->userHasAccessToCompany($this->user, $company->id));
    }

    public function test_user_has_access_to_company_is_false_for_a_revoked_membership(): void
    {
        $company = $this->company();
        $this->membership($company, MembershipStatus::Revoked);

        $this->assertFalse($this->context->userHasAccessToCompany($this->user, $company->id));
    }

    public function test_user_has_access_to_company_is_false_for_a_pending_membership(): void
    {
        $company = $this->company();
        $this->membership($company, MembershipStatus::Pending);

        $this->assertFalse($this->context->userHasAccessToCompany($this->user, $company->id));
    }

    public function test_get_default_company_skips_a_suspended_membership_and_returns_the_active_one(): void
    {
        $suspended = $this->company();
        $this->membership($suspended, MembershipStatus::Suspended);
        $active = $this->company();
        $this->membership($active, MembershipStatus::Active);

        $this->assertSame($active->id, $this->context->getDefaultCompanyForUser($this->user));
    }

    public function test_get_default_company_returns_null_when_only_inactive_memberships_exist(): void
    {
        $company = $this->company();
        $this->membership($company, MembershipStatus::Suspended);

        $this->assertNull($this->context->getDefaultCompanyForUser($this->user));
    }

    public function test_get_default_company_prefers_the_primary_active_membership(): void
    {
        // A non-primary membership created first, then the primary one — the
        // primary must win regardless of creation order.
        $secondary = $this->company();
        $this->membership($secondary, MembershipStatus::Active, isPrimary: false);
        $primary = $this->company();
        $this->membership($primary, MembershipStatus::Active, isPrimary: true);

        $this->assertSame($primary->id, $this->context->getDefaultCompanyForUser($this->user));
    }

    public function test_get_default_company_is_deterministic_across_repeated_calls(): void
    {
        // No primary flag anywhere: the oldest active membership must win, and
        // the result must be stable across calls (no arbitrary ->first()).
        $first = $this->company();
        $this->membership($first, MembershipStatus::Active);
        $second = $this->company();
        $this->membership($second, MembershipStatus::Active);
        $third = $this->company();
        $this->membership($third, MembershipStatus::Active);

        $resolved = $this->context->getDefaultCompanyForUser($this->user);

        $this->assertSame($first->id, $resolved);
        $this->assertSame($resolved, $this->context->getDefaultCompanyForUser($this->user));
        $this->assertSame($resolved, $this->context->getDefaultCompanyForUser($this->user));
    }
}
