<?php

declare(strict_types=1);

namespace Tests\Feature\Location;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationTaxFieldsModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_persists_and_casts_tax_identity_fields(): void
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'C',
            'legal_name' => 'C LLC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Branch A',
            'code' => 'BRA',
            'type' => LocationType::Shop,
            'is_default' => false,
            'is_active' => true,
            'tax_id' => '73282932000074',
            'vat_number' => 'FR40303265045',
            'legal_identifiers' => ['siret' => '73282932000074'],
        ]);

        $fresh = $location->fresh();

        $this->assertSame('73282932000074', $fresh?->tax_id);
        $this->assertSame('FR40303265045', $fresh?->vat_number);
        $this->assertSame(['siret' => '73282932000074'], $fresh?->legal_identifiers);
    }
}
