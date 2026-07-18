<?php

declare(strict_types=1);

namespace Tests\Feature\Company\Console;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillMembershipsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'T', 'slug' => 'cmd-bf-'.uniqid(), 'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function makeCompanyFor(Tenant $tenant, string $name): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'legal_name' => $name.' SARL',
            'country_code' => 'FR', 'currency' => 'EUR', 'locale' => 'fr',
            'timezone' => 'Europe/Paris', 'date_format' => 'd/m/Y', 'status' => CompanyStatus::Active,
        ]);
    }

    private function makeMemberlessUser(Tenant $tenant, UserStatus $status = UserStatus::Active): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => $status]);
        UserCompanyMembership::where('user_id', $user->id)->delete();

        return $user;
    }

    private function runMigration(): void
    {
        $migration = require __DIR__.'/../../../../database/migrations/tenant/2026_07_16_100000_backfill_user_company_memberships.php';
        $migration->up();
    }

    public function test_named_user_maps_only_that_memberless_user_and_rerun_is_a_noop(): void
    {
        $tenant = $this->makeTenant();
        $companyA = $this->makeCompanyFor($tenant, 'A');
        $this->makeCompanyFor($tenant, 'B');
        $namedUser = $this->makeMemberlessUser($tenant);
        $otherUser = $this->makeMemberlessUser($tenant);

        $this->runMigration();
        self::assertSame(0, UserCompanyMembership::whereIn('user_id', [$namedUser->id, $otherUser->id])->count());

        $this->artisan('users:backfill-memberships', [
            '--company' => $companyA->id,
            '--user' => [$namedUser->id],
        ])
            ->expectsOutputToContain("User {$namedUser->id}: inserted 1 membership(s).")
            ->assertSuccessful();

        self::assertSame(1, UserCompanyMembership::where('user_id', $namedUser->id)
            ->where('company_id', $companyA->id)->count());
        self::assertSame(0, UserCompanyMembership::where('user_id', $otherUser->id)->count());

        $membership = UserCompanyMembership::where('user_id', $namedUser->id)
            ->where('company_id', $companyA->id)->firstOrFail();
        self::assertNull($membership->allowed_location_ids);
        self::assertFalse($membership->is_primary);
        self::assertSame('viewer', $membership->role->value);
        self::assertSame('active', $membership->status->value);

        $this->artisan('users:backfill-memberships', [
            '--company' => $companyA->id,
            '--user' => [$namedUser->id],
        ])
            ->expectsOutputToContain("User {$namedUser->id}: inserted 0 membership(s).")
            ->assertSuccessful();

        self::assertSame(1, UserCompanyMembership::where('user_id', $namedUser->id)
            ->where('company_id', $companyA->id)->count());
    }

    public function test_comma_separated_user_option_maps_each_explicitly_named_user(): void
    {
        $tenant = $this->makeTenant();
        $company = $this->makeCompanyFor($tenant, 'A');
        $firstUser = $this->makeMemberlessUser($tenant);
        $secondUser = $this->makeMemberlessUser($tenant);

        $this->artisan(sprintf(
            'users:backfill-memberships --company=%s --user=%s,%s',
            $company->id,
            $firstUser->id,
            $secondUser->id,
        ))->assertSuccessful();

        self::assertSame(2, UserCompanyMembership::whereIn('user_id', [$firstUser->id, $secondUser->id])
            ->where('company_id', $company->id)
            ->count());
    }

    public function test_unknown_company_fails_without_writing_rows(): void
    {
        $tenant = $this->makeTenant();
        $this->makeCompanyFor($tenant, 'A');
        $user = $this->makeMemberlessUser($tenant);

        $this->artisan('users:backfill-memberships', [
            '--company' => (string) Str::uuid(),
            '--user' => [$user->id],
        ])->assertExitCode(Command::FAILURE);

        self::assertSame(0, UserCompanyMembership::where('user_id', $user->id)->count());
    }

    public function test_bare_company_fails_without_implicit_bulk_grant(): void
    {
        $tenant = $this->makeTenant();
        $companyA = $this->makeCompanyFor($tenant, 'A');
        $this->makeCompanyFor($tenant, 'B');
        $user = $this->makeMemberlessUser($tenant);

        $this->artisan('users:backfill-memberships', [
            '--company' => $companyA->id,
        ])->assertExitCode(Command::FAILURE);

        self::assertSame(0, UserCompanyMembership::where('user_id', $user->id)->count());
    }

    public function test_multi_company_bulk_without_force_fails_without_writing_rows(): void
    {
        $tenant = $this->makeTenant();
        $companyA = $this->makeCompanyFor($tenant, 'A');
        $this->makeCompanyFor($tenant, 'B');
        $user = $this->makeMemberlessUser($tenant);

        $this->artisan('users:backfill-memberships', [
            '--company' => $companyA->id,
            '--all-memberless' => true,
        ])->assertExitCode(Command::FAILURE);

        self::assertSame(0, UserCompanyMembership::where('user_id', $user->id)->count());
    }

    public function test_forced_multi_company_bulk_maps_all_memberless_users_and_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $companyA = $this->makeCompanyFor($tenant, 'A');
        $this->makeCompanyFor($tenant, 'B');
        $firstUser = $this->makeMemberlessUser($tenant);
        $secondUser = $this->makeMemberlessUser($tenant);

        $arguments = [
            '--company' => $companyA->id,
            '--all-memberless' => true,
            '--force-multi' => true,
        ];

        $this->artisan('users:backfill-memberships', $arguments)->assertSuccessful();

        self::assertSame(1, UserCompanyMembership::where('user_id', $firstUser->id)
            ->where('company_id', $companyA->id)->count());
        self::assertSame(1, UserCompanyMembership::where('user_id', $secondUser->id)
            ->where('company_id', $companyA->id)->count());

        $this->artisan('users:backfill-memberships', $arguments)->assertSuccessful();

        self::assertSame(2, UserCompanyMembership::whereIn('user_id', [$firstUser->id, $secondUser->id])
            ->where('company_id', $companyA->id)->count());
    }

    public function test_single_company_bulk_does_not_require_force(): void
    {
        $tenant = $this->makeTenant();
        $company = $this->makeCompanyFor($tenant, 'A');
        $user = $this->makeMemberlessUser($tenant);

        $this->artisan('users:backfill-memberships', [
            '--company' => $company->id,
            '--all-memberless' => true,
        ])->assertSuccessful();

        self::assertSame(1, UserCompanyMembership::where('user_id', $user->id)
            ->where('company_id', $company->id)->count());
    }

    public function test_named_inactive_user_fails_without_writing_rows(): void
    {
        $tenant = $this->makeTenant();
        $company = $this->makeCompanyFor($tenant, 'A');
        $user = $this->makeMemberlessUser($tenant, UserStatus::Inactive);

        $this->artisan('users:backfill-memberships', [
            '--company' => $company->id,
            '--user' => [$user->id],
        ])->assertExitCode(Command::FAILURE);

        self::assertSame(0, UserCompanyMembership::where('user_id', $user->id)->count());
    }

    public function test_named_unknown_user_fails_without_writing_rows(): void
    {
        $tenant = $this->makeTenant();
        $company = $this->makeCompanyFor($tenant, 'A');

        $this->artisan('users:backfill-memberships', [
            '--company' => $company->id,
            '--user' => [(string) Str::uuid()],
        ])->assertExitCode(Command::FAILURE);

        self::assertSame(0, UserCompanyMembership::count());
    }

    public function test_named_and_bulk_modes_cannot_be_combined(): void
    {
        $tenant = $this->makeTenant();
        $company = $this->makeCompanyFor($tenant, 'A');
        $user = $this->makeMemberlessUser($tenant);

        $this->artisan('users:backfill-memberships', [
            '--company' => $company->id,
            '--user' => [$user->id],
            '--all-memberless' => true,
        ])->assertExitCode(Command::FAILURE);

        self::assertSame(0, UserCompanyMembership::where('user_id', $user->id)->count());
    }
}
