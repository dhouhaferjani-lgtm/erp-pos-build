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

    private function membership(Company $company, MembershipStatus $status): void
    {
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'role' => 'admin',
            'status' => $status->value,
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
}
