<?php

declare(strict_types=1);

namespace Tests\Feature\Location;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CreateLocationTaxPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => '732829320',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Shop',
            'code' => 'MAIN',
            'type' => LocationType::Shop,
            'is_default' => true,
            'is_active' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    public function test_store_persists_tax_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/locations', [
                'name' => 'Branch A',
                'type' => 'shop',
                'address_country' => 'FR',
                'tax_id' => '73282932000074',
                'vat_number' => 'FR40303265045',
                'legal_identifiers' => ['siret' => '73282932000074'],
            ]);

        $response->assertCreated();

        $location = Location::query()
            ->where('company_id', $this->company->id)
            ->where('name', 'Branch A')
            ->firstOrFail();

        $this->assertSame('73282932000074', $location->tax_id);
        $this->assertSame('FR40303265045', $location->vat_number);
        $this->assertSame(['siret' => '73282932000074'], $location->legal_identifiers);
    }

    public function test_resource_exposes_all_tax_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/locations', [
                'name' => 'Branch B',
                'type' => 'shop',
                'address_country' => 'FR',
                'tax_id' => '73282932000074',
                'vat_number' => 'FR40303265045',
                'legal_identifiers' => ['siret' => '73282932000074'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.tax_id', '73282932000074')
            ->assertJsonPath('data.vat_number', 'FR40303265045')
            ->assertJsonPath('data.legal_identifiers.siret', '73282932000074');
    }
}
