<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant\Concerns;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;

trait BuildsFreshTenantCensusFixture
{
    protected Tenant $tenant;

    protected Company $firstCompany;

    protected Company $secondCompany;

    protected Location $firstLocation;

    protected Location $secondLocation;

    protected Location $secondCompanyLocation;

    protected User $owner;

    protected function buildFreshTenantCensusFixture(): void
    {
        [$this->tenant, $this->firstCompany, $this->owner] = $this->makeTenantCompanyUser('TN');

        // Registration creates Main before TenantInitializationService seeds the
        // repositories. The ordering is load-bearing for drawer attribution.
        $this->firstLocation = Location::query()->create([
            'company_id' => $this->firstCompany->id,
            'name' => 'Main Location',
            'code' => 'MAIN',
            'type' => LocationType::Shop,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => true,
            'address_country' => 'TN',
        ]);

        UserCompanyMembership::query()->create([
            'user_id' => $this->owner->id,
            'company_id' => $this->firstCompany->id,
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
            'accepted_at' => now(),
        ]);

        app(TenantInitializationService::class)->initializeForNewRegistration(
            $this->tenant,
            $this->firstCompany,
            $this->owner,
        );

        $secondCompanyResponse = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Day One Second Company',
                'legal_name' => 'Day One Second Company SARL',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr_TN',
                'timezone' => 'Africa/Tunis',
                'tax_id' => 'TN-CENSUS-SECOND',
            ])
            ->assertCreated();

        $secondCompanyId = $secondCompanyResponse->json('data.id');
        $this->assertIsString($secondCompanyId);
        $this->secondCompany = Company::query()->findOrFail($secondCompanyId);
        $this->secondCompanyLocation = Location::query()
            ->where('company_id', $this->secondCompany->id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $secondLocationResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Company-Id', $this->firstCompany->id)
            ->postJson('/api/v1/locations', [
                'name' => 'Day One POS Branch',
                'code' => 'BRANCH',
                'type' => LocationType::Warehouse->value,
                'pos_enabled' => true,
                'address_country' => 'TN',
            ])
            ->assertCreated();

        $secondLocationId = $secondLocationResponse->json('data.id');
        $this->assertIsString($secondLocationId);
        $this->secondLocation = Location::query()->findOrFail($secondLocationId);
    }

    /**
     * Mirrors TenantReferenceDataSeedingTest::makeTenantCompanyUser().
     *
     * @return array{0: Tenant, 1: Company, 2: User}
     */
    private function makeTenantCompanyUser(string $countryCode): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = Tenant::query()->create([
            'name' => "Day One Tenant {$countryCode}",
            'slug' => 'day-one-tenant-'.strtolower($countryCode).'-'.$suffix,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => strtoupper($countryCode),
            'currency_code' => 'TND',
        ]);

        $company = Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => "Day One Company {$countryCode}",
            'legal_name' => "Day One Company {$countryCode} SARL",
            'tax_id' => 'TN-CENSUS-FIRST',
            'country_code' => strtoupper($countryCode),
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => "Day One Owner {$countryCode}",
            'email' => strtolower($countryCode).'-day-one-'.$suffix.'@example.test',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        return [$tenant, $company, $user];
    }
}
