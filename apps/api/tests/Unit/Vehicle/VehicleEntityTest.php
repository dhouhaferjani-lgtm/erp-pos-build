<?php

declare(strict_types=1);

namespace Tests\Unit\Vehicle;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleEntityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);
    }

    public function test_vehicle_has_uuid_primary_key(): void
    {
        $vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $this->assertIsString($vehicle->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $vehicle->id
        );
    }

    public function test_vehicle_belongs_to_tenant(): void
    {
        $vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $this->assertEquals($this->tenant->id, $vehicle->tenant_id);
        $this->assertEquals($this->tenant->id, $vehicle->tenant->id);
    }

    public function test_vehicle_can_belong_to_partner(): void
    {
        $vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $this->assertEquals($this->customer->id, $vehicle->partner_id);
        $this->assertEquals($this->customer->id, $vehicle->partner->id);
        $this->assertEquals('John Doe', $vehicle->partner->name);
    }

    public function test_vehicle_can_exist_without_partner(): void
    {
        $vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $this->assertNull($vehicle->partner_id);
        $this->assertNull($vehicle->partner);
    }

    public function test_vehicle_has_fillable_fields(): void
    {
        $vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
            'color' => 'Blue',
            'mileage' => 50000,
            'vin' => 'JT2BF22K1W0123456',
            'engine_code' => '1ZZ-FE',
            'fuel_type' => 'gasoline',
            'transmission' => 'automatic',
            'notes' => 'Customer vehicle notes',
        ]);

        $this->assertEquals('ABC-123', $vehicle->license_plate);
        $this->assertEquals('Toyota', $vehicle->brand);
        $this->assertEquals('Corolla', $vehicle->model);
        $this->assertEquals(2020, $vehicle->year);
        $this->assertEquals('Blue', $vehicle->color);
        $this->assertEquals(50000, $vehicle->mileage);
        $this->assertEquals('JT2BF22K1W0123456', $vehicle->vin);
        $this->assertEquals('1ZZ-FE', $vehicle->engine_code);
        $this->assertEquals('gasoline', $vehicle->fuel_type?->value);
        $this->assertEquals('automatic', $vehicle->transmission?->value);
        $this->assertEquals('Customer vehicle notes', $vehicle->notes);
    }

    public function test_vehicle_uses_soft_deletes(): void
    {
        $vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'DEL-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $vehicleId = $vehicle->id;
        $vehicle->delete();

        $this->assertNull(Vehicle::find($vehicleId));
        $this->assertNotNull(Vehicle::withTrashed()->find($vehicleId));
    }

    public function test_vehicle_display_name(): void
    {
        $vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
        ]);

        $this->assertEquals('ABC-123 - Toyota Corolla (2020)', $vehicle->getDisplayName());
    }

    public function test_vehicle_display_name_without_year(): void
    {
        $vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $this->assertEquals('ABC-123 - Toyota Corolla', $vehicle->getDisplayName());
    }

    public function test_vehicle_scope_for_tenant(): void
    {
        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'MY-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        Vehicle::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'license_plate' => 'OTHER-123',
            'brand' => 'Honda',
            'model' => 'Civic',
        ]);

        $vehicles = Vehicle::forTenant($this->tenant->id)->get();

        $this->assertCount(1, $vehicles);
        $this->assertEquals('MY-123', $vehicles->first()->license_plate);
    }

    public function test_vehicle_scope_for_partner(): void
    {
        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'license_plate' => 'OWNED-123',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'UNOWNED-123',
            'brand' => 'Honda',
            'model' => 'Civic',
        ]);

        $vehicles = Vehicle::forPartner($this->customer->id)->get();

        $this->assertCount(1, $vehicles);
        $this->assertEquals('OWNED-123', $vehicles->first()->license_plate);
    }
}
