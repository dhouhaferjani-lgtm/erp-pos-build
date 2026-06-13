<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CompanyStoreTaxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Countries must exist before CompanyTaxProvisioningService can seed tax configs.
        $this->seed(CountriesSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@taxtest.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        // Create an existing company + membership so CompanyContextMiddleware allows requests.
        $existingCompany = Company::factory()->for($this->tenant)->create();
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $existingCompany->id,
            'role' => MembershipRole::Owner,
        ]);
    }

    public function test_creating_a_tn_company_provisions_tax_configurations(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Tunisia Test Company',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr_TN',
                'timezone' => 'Africa/Tunis',
            ]);

        $response->assertCreated();

        // At least 4 TN tax configurations should have been seeded.
        $this->assertGreaterThanOrEqual(
            4,
            TaxConfiguration::where('country_code', 'TN')->count(),
            'Expected at least 4 TN TaxConfiguration rows after company creation.',
        );

        // The created company must have a non-null default_tax_configuration_id.
        $companyId = $response->json('data.id');
        $company = Company::findOrFail($companyId);

        $this->assertNotNull(
            $company->default_tax_configuration_id,
            'Company default_tax_configuration_id should be set after creation.',
        );
    }
}
