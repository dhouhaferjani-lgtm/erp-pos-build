<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class LocationScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $locationA;

    private Location $locationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->locationA = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Shop A',
        ]);
        $this->locationB = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Shop B',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_fail_closed_when_requesting_out_of_scope_location(): void
    {
        $user = $this->userWithMembership([$this->locationA->id]);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($user, [$this->locationB->id]);
    }

    public function test_subset_request_within_allowed_set_returns_request(): void
    {
        $user = $this->userWithMembership([$this->locationA->id, $this->locationB->id]);

        $this->assertSame(
            [$this->locationA->id],
            $this->resolver()->resolve($user, [$this->locationA->id]),
        );
    }

    public function test_empty_request_returns_full_allowed_set(): void
    {
        $user = $this->userWithMembership([$this->locationA->id]);

        $this->assertSame([$this->locationA->id], $this->resolver()->resolve($user, []));
    }

    public function test_null_membership_post_backfill_resolves_to_all_company_locations(): void
    {
        $user = $this->userWithMembership(null);

        $result = $this->resolver()->resolve($user, []);
        sort($result);
        $expected = [$this->locationA->id, $this->locationB->id];
        sort($expected);

        $this->assertSame($expected, $result);
    }

    public function test_bypass_permission_grants_full_company_set_despite_restriction(): void
    {
        $user = $this->userWithMembership(
            [$this->locationA->id],
            grant: 'replenishment.process',
        );

        $this->assertSame(
            [$this->locationB->id],
            $this->resolver()->resolve(
                $user,
                [$this->locationB->id],
                'replenishment.process',
            ),
        );
    }

    public function test_absent_membership_resolves_to_deny_all(): void
    {
        $user = User::factory()->for($this->tenant)->create();

        $this->assertSame([], $this->resolver()->resolve($user, []));
    }

    /**
     * @param  list<string>|null  $allowedLocationIds
     */
    private function userWithMembership(?array $allowedLocationIds, ?string $grant = null): User
    {
        $user = User::factory()->for($this->tenant)->create();

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'allowed_location_ids' => $allowedLocationIds,
            'status' => MembershipStatus::Active,
        ]);

        if ($grant !== null) {
            Permission::findOrCreate($grant, 'sanctum');
            $user->givePermissionTo($grant);
        }

        return $user;
    }

    private function resolver(): LocationScopeResolver
    {
        return app(LocationScopeResolver::class);
    }
}
