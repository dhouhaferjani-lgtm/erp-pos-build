<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TaxConfigurationCapabilityDelegationTest extends TestCase
{
    use RefreshDatabase;

    public function test_capability_response_remains_true_for_tn_and_false_for_fr(): void
    {
        // Production break caught: moving the predicate changes the existing Taxation HTTP contract.
        [$user, $tn, $fr] = $this->makeUserAndCompanies();
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Company-ID' => $tn->id])
            ->getJson('/api/v1/taxation/configurations/capabilities')
            ->assertOk()
            ->assertExactJson(['data' => ['supports_stamp_duty' => true]]);

        $this->withHeaders(['X-Company-ID' => $fr->id])
            ->getJson('/api/v1/taxation/configurations/capabilities')
            ->assertOk()
            ->assertExactJson(['data' => ['supports_stamp_duty' => false]]);
    }

    public function test_controller_response_is_driven_by_the_injected_shared_authority(): void
    {
        // Production break caught: Taxation retains a hidden country predicate instead of delegating.
        [$user, $tn] = $this->makeUserAndCompanies();
        $this->app->instance(CountryAccountingCapabilities::class, new class implements CountryAccountingCapabilities
        {
            public function supportsStampDuty(string $countryCode): bool
            {
                return false;
            }

            public function version(): string
            {
                return 'test-only';
            }
        });
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Company-ID' => $tn->id])
            ->getJson('/api/v1/taxation/configurations/capabilities')
            ->assertOk()
            ->assertExactJson(['data' => ['supports_stamp_duty' => false]]);
    }

    /** @return array{User, Company, Company} */
    private function makeUserAndCompanies(): array
    {
        $this->seed(CountriesSeeder::class);
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create([
            'name' => 'Capability Delegation Tenant',
            'slug' => 'capability-delegation-'.Str::uuid()->toString(),
            'country_code' => 'TN',
            'currency_code' => 'TND',
            'status' => 'active',
            'plan' => 'professional',
        ]);
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $user = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => 'Capability Reader',
            'email' => 'capability-'.Str::uuid()->toString().'@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $tn = $this->makeCompany($tenant, 'TN', 'TND');
        $fr = $this->makeCompany($tenant, 'FR', 'EUR');
        foreach ([$tn, $fr] as $index => $company) {
            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'role' => MembershipRole::Manager,
                'status' => MembershipStatus::Active,
                'is_primary' => $index === 0,
            ]);
        }
        $user->givePermissionTo('documents.view');

        return [$user, $tn, $fr];
    }

    private function makeCompany(Tenant $tenant, string $country, string $currency): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => $country.' Company',
            'country_code' => $country,
            'currency' => $currency,
            'locale' => 'fr',
            'timezone' => 'UTC',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '0.00',
        ]);
    }
}
