<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;

final class CompanyCreationRollbackTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    public function test_additional_company_timbre_assignment_failure_rolls_back_every_downstream_row(): void
    {
        config(['country_defaults.provisioning_enabled' => true]);
        $this->assignWildcard();
        $tenant = Tenant::query()->create([
            'name' => 'M5 existing tenant',
            'slug' => 'm5-existing-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'M5 owner',
            'email' => 'm5-owner@example.test',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');
        $existing = Company::factory()->for($tenant)->create();
        UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $existing->id,
            'role' => MembershipRole::Owner,
        ]);
        $before = [
            'companies' => Company::query()->count(),
            'memberships' => UserCompanyMembership::query()->count(),
            'locations' => DB::table('locations')->count(),
            'hash_chains' => DB::table('company_hash_chains')->count(),
        ];

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/companies', [
            'name' => 'Must Roll Back',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'en',
            'timezone' => 'UTC',
        ])->assertUnprocessable()->assertSee('requires an exact template assignment');

        self::assertSame($before['companies'], Company::query()->count());
        self::assertSame($before['memberships'], UserCompanyMembership::query()->count());
        self::assertSame($before['locations'], DB::table('locations')->count());
        self::assertSame($before['hash_chains'], DB::table('company_hash_chains')->count());
        self::assertDatabaseMissing('companies', ['name' => 'Must Roll Back']);
    }

    public function test_registration_timbre_assignment_failure_rolls_back_tenant_company_and_user(): void
    {
        config(['country_defaults.provisioning_enabled' => true]);
        $this->assignWildcard();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'M5 Registration Owner',
            'email' => 'm5-registration@example.test',
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => 'M5 Registration Must Roll Back',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'en',
            'timezone' => 'UTC',
            'vertical' => Vertical::Retail->value,
        ])->assertUnprocessable()->assertSee('requires an exact template assignment');

        self::assertDatabaseMissing('tenants', ['name' => 'M5 Registration Must Roll Back']);
        self::assertDatabaseMissing('companies', ['name' => 'M5 Registration Must Roll Back']);
        self::assertDatabaseMissing('users', ['email' => 'm5-registration@example.test']);
    }

    private function assignWildcard(): void
    {
        $actor = $this->m4Actor();
        $template = $this->m4Published('generic', '*', $actor);
        $this->m4Assign('*', $template, $actor);
    }
}
