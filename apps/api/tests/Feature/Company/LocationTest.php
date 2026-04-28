<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0.1.3: Tests for locations table.
 *
 * Location = Physical place (shop, warehouse, office, mobile).
 * Each company can have multiple locations. Stock is tracked per location.
 */
class LocationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Account',
            'slug' => 'test-account',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'ACME Garage',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);
    }

    public function test_location_can_be_created_with_required_fields(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Shop',
            'type' => LocationType::Shop,
        ]);

        $this->assertDatabaseHas('locations', [
            'company_id' => $this->company->id,
            'name' => 'Main Shop',
            'type' => 'shop',
        ]);

        $this->assertNotNull($location->id);
        $this->assertEquals(36, strlen($location->id)); // UUID length
    }

    public function test_location_belongs_to_company(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse',
            'type' => LocationType::Warehouse,
        ]);

        $this->assertEquals($this->company->id, $location->company->id);
    }

    public function test_company_can_have_multiple_locations(): void
    {
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Shop',
            'type' => LocationType::Shop,
        ]);

        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse',
            'type' => LocationType::Warehouse,
        ]);

        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Office',
            'type' => LocationType::Office,
        ]);

        $this->assertCount(3, $this->company->fresh()->locations);
    }

    public function test_location_has_address_fields(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Downtown Shop',
            'type' => LocationType::Shop,
            'address_street' => '123 Avenue Bourguiba',
            'address_city' => 'Tunis',
            'address_postal_code' => '1000',
            'address_country' => 'TN',
        ]);

        $this->assertEquals('123 Avenue Bourguiba', $location->address_street);
        $this->assertEquals('Tunis', $location->address_city);
        $this->assertEquals('1000', $location->address_postal_code);
        $this->assertEquals('TN', $location->address_country);
    }

    public function test_location_has_geo_fields(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Mobile Unit',
            'type' => LocationType::Mobile,
            'latitude' => 36.80648,
            'longitude' => 10.18146,
        ]);

        $this->assertEquals(36.80648, $location->latitude);
        $this->assertEquals(10.18146, $location->longitude);
    }

    public function test_location_has_pos_settings(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'POS Shop',
            'type' => LocationType::Shop,
            'pos_enabled' => true,
            'receipt_header' => 'Welcome to ACME!',
            'receipt_footer' => 'Thank you for your visit!',
        ]);

        $this->assertTrue($location->pos_enabled);
        $this->assertEquals('Welcome to ACME!', $location->receipt_header);
        $this->assertEquals('Thank you for your visit!', $location->receipt_footer);
    }

    public function test_location_has_default_values(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Basic Location',
            'type' => LocationType::Shop,
        ]);

        $location->refresh();

        $this->assertFalse($location->is_default);
        $this->assertTrue($location->is_active);
        $this->assertFalse($location->pos_enabled);
    }

    public function test_location_code_unique_within_company(): void
    {
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'First Location',
            'code' => 'LOC-001',
            'type' => LocationType::Shop,
        ]);

        $this->expectException(QueryException::class);

        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Second Location',
            'code' => 'LOC-001', // Duplicate within same company
            'type' => LocationType::Warehouse,
        ]);
    }

    public function test_location_code_can_be_same_across_different_companies(): void
    {
        $company2 = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Another Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        $location1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Company1 Location',
            'code' => 'LOC-001',
            'type' => LocationType::Shop,
        ]);

        $location2 = Location::create([
            'company_id' => $company2->id,
            'name' => 'Company2 Location',
            'code' => 'LOC-001', // OK - different company
            'type' => LocationType::Shop,
        ]);

        $this->assertNotEquals($location1->id, $location2->id);
    }

    public function test_location_type_enum(): void
    {
        $shop = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Shop',
            'type' => LocationType::Shop,
        ]);

        $warehouse = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse',
            'type' => LocationType::Warehouse,
        ]);

        $this->assertEquals(LocationType::Shop, $shop->type);
        $this->assertEquals(LocationType::Warehouse, $warehouse->type);
    }

    public function test_default_location_scope(): void
    {
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Non-default',
            'type' => LocationType::Shop,
            'is_default' => false,
        ]);

        $defaultLocation = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Default Location',
            'type' => LocationType::Shop,
            'is_default' => true,
        ]);

        $default = Location::where('company_id', $this->company->id)
            ->where('is_default', true)
            ->first();

        $this->assertEquals($defaultLocation->id, $default->id);
    }
}
