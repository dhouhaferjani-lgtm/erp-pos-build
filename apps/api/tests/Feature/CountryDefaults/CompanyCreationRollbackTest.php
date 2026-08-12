<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'COUNTRY_DEFAULTS_PROVISIONING_UNAVAILABLE')
            ->assertJsonPath('error.message', 'Company setup is temporarily unavailable. Please try again later or contact support.')
            ->assertDontSee('template assignment');

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
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'COUNTRY_DEFAULTS_PROVISIONING_UNAVAILABLE')
            ->assertJsonPath('error.message', 'Company setup is temporarily unavailable. Please try again later or contact support.')
            ->assertDontSee('template assignment');

        self::assertDatabaseMissing('tenants', ['name' => 'M5 Registration Must Roll Back']);
        self::assertDatabaseMissing('companies', ['name' => 'M5 Registration Must Roll Back']);
        self::assertDatabaseMissing('users', ['email' => 'm5-registration@example.test']);
    }

    public function test_missing_wildcard_is_public_safe_and_translated_in_french(): void
    {
        config(['country_defaults.provisioning_enabled' => true]);
        [$tenant, $user] = $this->tenantUser();
        $before = Company::query()->count();

        $this->withHeader('Accept-Language', 'fr')->actingAs($user, 'sanctum')->postJson('/api/v1/companies', [
            'name' => 'Wildcard absent',
            'country_code' => 'ZZ',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'UTC',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'COUNTRY_DEFAULTS_PROVISIONING_UNAVAILABLE')
            ->assertJsonPath('error.message', 'La création de l’entreprise est temporairement indisponible. Réessayez plus tard ou contactez le support.')
            ->assertDontSee('wildcard');

        self::assertSame($before, Company::query()->count());
        self::assertDatabaseMissing('companies', ['name' => 'Wildcard absent']);
        self::assertSame($tenant->id, $user->tenant_id);
    }

    public function test_unpublished_assignment_is_public_safe_and_rolls_back(): void
    {
        config(['country_defaults.provisioning_enabled' => true]);
        $template = $this->assignWildcard();
        DB::connection($template->getConnectionName())->table('admin_templates')
            ->where('id', $template->id)
            ->update(['status' => TemplateStatus::Draft->value]);
        [, $user] = $this->tenantUser();

        $this->postAdditionalCompany($user, 'Unpublished assignment')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'COUNTRY_DEFAULTS_PROVISIONING_UNAVAILABLE')
            ->assertJsonPath('error.message', 'Company setup is temporarily unavailable. Please try again later or contact support.')
            ->assertDontSee('published template');

        self::assertDatabaseMissing('companies', ['name' => 'Unpublished assignment']);
    }

    public function test_stale_assignment_is_public_safe_and_rolls_back(): void
    {
        config(['country_defaults.provisioning_enabled' => true]);
        $this->assignWildcard();
        $this->app->bind(CountryAccountingCapabilities::class, static fn (): CountryAccountingCapabilities => new class implements CountryAccountingCapabilities
        {
            public function supportsStampDuty(string $countryCode): bool
            {
                return false;
            }

            public function version(): string
            {
                return 'review-stale-version';
            }
        });
        [, $user] = $this->tenantUser();

        $this->postAdditionalCompany($user, 'Stale assignment')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'COUNTRY_DEFAULTS_PROVISIONING_UNAVAILABLE')
            ->assertJsonPath('error.message', 'Company setup is temporarily unavailable. Please try again later or contact support.')
            ->assertDontSee('registry version');

        self::assertDatabaseMissing('companies', ['name' => 'Stale assignment']);
    }

    /** @return array{Tenant, User} */
    private function tenantUser(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'M5 public boundary',
            'slug' => 'm5-public-boundary-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'M5 public owner',
            'email' => 'm5-public-'.uniqid().'@example.test',
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

        return [$tenant, $user];
    }

    private function postAdditionalCompany(User $user, string $name): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/companies', [
            'name' => $name,
            'country_code' => 'ZZ',
            'currency' => 'EUR',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);
    }

    private function assignWildcard(): AdminTemplate
    {
        $actor = $this->m4Actor();
        $template = $this->m4Published('generic', '*', $actor);
        $this->m4Assign('*', $template, $actor);

        return $template;
    }
}
