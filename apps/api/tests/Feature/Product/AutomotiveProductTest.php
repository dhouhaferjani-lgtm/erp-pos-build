<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\AutomotiveProductCriterion;
use App\Modules\Product\Domain\AutomotiveProductCrossReference;
use App\Modules\Product\Domain\AutomotiveProductMetadata;
use App\Modules\Product\Domain\AutomotiveProductVehicle;
use App\Modules\Product\Domain\Enums\CrossReferenceType;
use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class AutomotiveProductTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Mechanic',
            'slug' => 'test-mechanic',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Mechanic Company',
            'legal_name' => 'Test Mechanic Company SARL',
            'tax_id' => 'TAX-TN-001',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Mechanic User',
            'email' => 'mechanic@test.tn',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /** @test */
    public function it_can_create_product_with_automotive_metadata_via_api(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Bosch Brake Pad Set',
                'sku' => 'BP-0986-494',
                'type' => 'part',
                'sale_price' => 45.50,
                'automotive_metadata' => [
                    'platform_link_status' => 'unlinked',
                    'article_number' => '0986494123',
                    'supplier_brand' => 'Bosch',
                    'product_group_name' => 'Brake Pads',
                    'brand_quality_tier' => 'oes',
                    'article_status' => 'active',
                    'confidence_score' => 85,
                    'data_source' => 'manual',
                    'weight_kg' => 1.250,
                    'is_universal_fit' => false,
                    'notes' => 'Front axle brake pad set',
                    'cross_references' => [
                        [
                            'reference_type' => 'oe',
                            'reference_number' => 'ABC123',
                            'manufacturer_name' => 'Toyota',
                        ],
                    ],
                    'vehicles' => [
                        [
                            'vehicle_type' => 'pc',
                            'vehicle_display' => 'Toyota Corolla 1.8 2019-2023',
                            'year_from' => 2019,
                            'year_to' => 2023,
                        ],
                    ],
                    'criteria' => [
                        [
                            'criteria_key' => 'length_mm',
                            'criteria_label' => 'Length',
                            'value' => '450',
                            'unit' => 'mm',
                            'sort_order' => 1,
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Bosch Brake Pad Set')
            ->assertJsonPath('data.automotive_metadata.article_number', '0986494123')
            ->assertJsonPath('data.automotive_metadata.supplier_brand', 'Bosch')
            ->assertJsonPath('data.automotive_metadata.product_group_name', 'Brake Pads')
            ->assertJsonPath('data.automotive_metadata.brand_quality_tier', 'oes')
            ->assertJsonPath('data.automotive_metadata.article_status', 'active')
            ->assertJsonPath('data.automotive_metadata.confidence_score', 85)
            ->assertJsonPath('data.automotive_metadata.is_universal_fit', false)
            ->assertJsonPath('data.automotive_metadata.cross_references.0.reference_type', 'oe')
            ->assertJsonPath('data.automotive_metadata.cross_references.0.reference_number', 'ABC123')
            ->assertJsonPath('data.automotive_metadata.cross_references.0.manufacturer_name', 'Toyota')
            ->assertJsonPath('data.automotive_metadata.vehicles.0.vehicle_type', 'pc')
            ->assertJsonPath('data.automotive_metadata.vehicles.0.vehicle_display', 'Toyota Corolla 1.8 2019-2023')
            ->assertJsonPath('data.automotive_metadata.vehicles.0.year_from', 2019)
            ->assertJsonPath('data.automotive_metadata.vehicles.0.year_to', 2023)
            ->assertJsonPath('data.automotive_metadata.criteria.0.criteria_key', 'length_mm')
            ->assertJsonPath('data.automotive_metadata.criteria.0.value', '450')
            ->assertJsonPath('data.automotive_metadata.criteria.0.unit', 'mm');

        $this->assertDatabaseHas('products', [
            'name' => 'Bosch Brake Pad Set',
            'sku' => 'BP-0986-494',
        ]);

        $this->assertDatabaseHas('automotive_product_metadata', [
            'article_number' => '0986494123',
            'supplier_brand' => 'Bosch',
            'article_status' => 'active',
        ]);

        $this->assertDatabaseHas('automotive_product_cross_references', [
            'reference_type' => 'oe',
            'reference_number' => 'ABC123',
            'manufacturer_name' => 'Toyota',
        ]);

        $this->assertDatabaseHas('automotive_product_vehicles', [
            'vehicle_type' => 'pc',
            'vehicle_display' => 'Toyota Corolla 1.8 2019-2023',
            'year_from' => 2019,
            'year_to' => 2023,
        ]);

        $this->assertDatabaseHas('automotive_product_criteria', [
            'criteria_key' => 'length_mm',
            'value' => '450',
            'unit' => 'mm',
        ]);
    }

    /** @test */
    public function it_can_update_product_automotive_metadata_via_api(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Old Brake Pad',
            'sku' => 'OLD-BP-001',
        ]);

        $metadata = AutomotiveProductMetadata::factory()->create([
            'product_id' => $product->id,
            'article_number' => 'OLD-123',
            'supplier_brand' => 'TRW',
        ]);

        $oldCrossRef = AutomotiveProductCrossReference::create([
            'automotive_metadata_id' => $metadata->id,
            'reference_type' => CrossReferenceType::Oe,
            'reference_number' => 'OLD-REF',
            'manufacturer_name' => 'Honda',
        ]);

        $oldVehicle = AutomotiveProductVehicle::create([
            'automotive_metadata_id' => $metadata->id,
            'vehicle_type' => VehicleTypeRef::Pc,
            'vehicle_display' => 'Honda Civic 2015-2018',
            'year_from' => 2015,
            'year_to' => 2018,
        ]);

        $oldCriterion = AutomotiveProductCriterion::create([
            'automotive_metadata_id' => $metadata->id,
            'criteria_key' => 'width_mm',
            'criteria_label' => 'Width',
            'value' => '100',
            'unit' => 'mm',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'automotive_metadata' => [
                    'article_number' => 'NEW-456',
                    'supplier_brand' => 'Brembo',
                    'cross_references' => [
                        [
                            'reference_type' => 'oem',
                            'reference_number' => 'NEW-REF-001',
                            'manufacturer_name' => 'BMW',
                        ],
                        [
                            'reference_type' => 'ean',
                            'reference_number' => '4005209123456',
                        ],
                    ],
                    'vehicles' => [
                        [
                            'vehicle_type' => 'pc',
                            'vehicle_display' => 'BMW 3 Series (E90) 2005-2011',
                            'year_from' => 2005,
                            'year_to' => 2011,
                        ],
                    ],
                    'criteria' => [
                        [
                            'criteria_key' => 'height_mm',
                            'criteria_label' => 'Height',
                            'value' => '55',
                            'unit' => 'mm',
                            'sort_order' => 1,
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.automotive_metadata.article_number', 'NEW-456')
            ->assertJsonPath('data.automotive_metadata.supplier_brand', 'Brembo')
            ->assertJsonPath('data.automotive_metadata.cross_references.0.reference_type', 'oem')
            ->assertJsonPath('data.automotive_metadata.cross_references.0.reference_number', 'NEW-REF-001')
            ->assertJsonPath('data.automotive_metadata.vehicles.0.vehicle_display', 'BMW 3 Series (E90) 2005-2011')
            ->assertJsonPath('data.automotive_metadata.criteria.0.criteria_key', 'height_mm');

        // Old nested records should be replaced
        $this->assertDatabaseMissing('automotive_product_cross_references', [
            'id' => $oldCrossRef->id,
        ]);
        $this->assertDatabaseMissing('automotive_product_vehicles', [
            'id' => $oldVehicle->id,
        ]);
        $this->assertDatabaseMissing('automotive_product_criteria', [
            'id' => $oldCriterion->id,
        ]);

        // New records should exist
        $this->assertDatabaseHas('automotive_product_metadata', [
            'product_id' => $product->id,
            'article_number' => 'NEW-456',
            'supplier_brand' => 'Brembo',
        ]);

        $this->assertDatabaseHas('automotive_product_cross_references', [
            'reference_number' => 'NEW-REF-001',
            'manufacturer_name' => 'BMW',
        ]);

        // Verify count: 2 new cross refs
        $freshMetadata = AutomotiveProductMetadata::where('product_id', $product->id)->first();
        $this->assertNotNull($freshMetadata);
        $this->assertCount(2, $freshMetadata->crossReferences);
        $this->assertCount(1, $freshMetadata->vehicles);
        $this->assertCount(1, $freshMetadata->criteria);
    }

    /** @test */
    public function it_loads_automotive_metadata_when_fetching_single_product(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Filter',
            'sku' => 'OF-MANN-001',
        ]);

        $metadata = AutomotiveProductMetadata::factory()->create([
            'product_id' => $product->id,
            'article_number' => 'W712/52',
            'supplier_brand' => 'Mann-Filter',
            'product_group_name' => 'Oil Filters',
        ]);

        AutomotiveProductCrossReference::create([
            'automotive_metadata_id' => $metadata->id,
            'reference_type' => CrossReferenceType::Oe,
            'reference_number' => '04E115561H',
            'manufacturer_name' => 'Volkswagen',
        ]);

        AutomotiveProductVehicle::create([
            'automotive_metadata_id' => $metadata->id,
            'vehicle_type' => VehicleTypeRef::Pc,
            'vehicle_display' => 'VW Golf VII 1.4 TSI 2012-2020',
            'year_from' => 2012,
            'year_to' => 2020,
        ]);

        AutomotiveProductCriterion::create([
            'automotive_metadata_id' => $metadata->id,
            'criteria_key' => 'outer_diameter_mm',
            'criteria_label' => 'Outer Diameter',
            'value' => '76',
            'unit' => 'mm',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.automotive_metadata.article_number', 'W712/52')
            ->assertJsonPath('data.automotive_metadata.supplier_brand', 'Mann-Filter')
            ->assertJsonPath('data.automotive_metadata.cross_references.0.reference_number', '04E115561H')
            ->assertJsonPath('data.automotive_metadata.vehicles.0.vehicle_display', 'VW Golf VII 1.4 TSI 2012-2020')
            ->assertJsonPath('data.automotive_metadata.criteria.0.criteria_key', 'outer_diameter_mm');
    }

    /** @test */
    public function it_loads_automotive_metadata_when_listing_products(): void
    {
        $product1 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Brake Disc Front',
            'sku' => 'BD-001',
        ]);

        AutomotiveProductMetadata::factory()->create([
            'product_id' => $product1->id,
            'supplier_brand' => 'Brembo',
            'product_group_name' => 'Brake Discs',
        ]);

        $product2 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Spark Plug',
            'sku' => 'SP-001',
        ]);

        AutomotiveProductMetadata::factory()->create([
            'product_id' => $product2->id,
            'supplier_brand' => 'NGK',
            'product_group_name' => 'Spark Plugs',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Both products should have automotive_metadata
        $supplierBrands = array_map(
            fn (array $p) => $p['automotive_metadata']['supplier_brand'] ?? null,
            $data
        );
        $this->assertContains('Brembo', $supplierBrands);
        $this->assertContains('NGK', $supplierBrands);
    }

    /** @test */
    public function it_cascades_delete_metadata_when_product_is_deleted(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Delete Test Product',
            'sku' => 'DEL-001',
        ]);

        $metadata = AutomotiveProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        $crossRef = AutomotiveProductCrossReference::create([
            'automotive_metadata_id' => $metadata->id,
            'reference_type' => CrossReferenceType::Oe,
            'reference_number' => 'DEL-REF',
        ]);

        $vehicle = AutomotiveProductVehicle::create([
            'automotive_metadata_id' => $metadata->id,
            'vehicle_type' => VehicleTypeRef::Pc,
            'vehicle_display' => 'Test Vehicle',
        ]);

        $criterion = AutomotiveProductCriterion::create([
            'automotive_metadata_id' => $metadata->id,
            'criteria_key' => 'test_key',
            'criteria_label' => 'Test',
            'value' => '100',
            'sort_order' => 0,
        ]);

        $metadataId = $metadata->id;
        $crossRefId = $crossRef->id;
        $vehicleId = $vehicle->id;
        $criterionId = $criterion->id;

        $product->forceDelete();

        $this->assertDatabaseMissing('automotive_product_metadata', ['id' => $metadataId]);
        $this->assertDatabaseMissing('automotive_product_cross_references', ['id' => $crossRefId]);
        $this->assertDatabaseMissing('automotive_product_vehicles', ['id' => $vehicleId]);
        $this->assertDatabaseMissing('automotive_product_criteria', ['id' => $criterionId]);
    }

    /** @test */
    public function it_does_not_load_automotive_metadata_for_non_automotive_vertical(): void
    {
        $parapharmacyTenant = Tenant::create([
            'name' => 'Test Parapharmacy',
            'slug' => 'test-parapharmacy',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $parapharmacyCompany = Company::create([
            'tenant_id' => $parapharmacyTenant->id,
            'name' => 'Test Parapharmacy Company',
            'legal_name' => 'Test Parapharmacy Company LLC',
            'tax_id' => 'TAX-PARA-001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($parapharmacyTenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $parapharmacyUser = User::create([
            'tenant_id' => $parapharmacyTenant->id,
            'name' => 'Parapharmacy User',
            'email' => 'para@test.fr',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $parapharmacyUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $parapharmacyUser->id,
            'company_id' => $parapharmacyCompany->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($parapharmacyCompany->id);

        $product = Product::factory()->create([
            'tenant_id' => $parapharmacyTenant->id,
            'company_id' => $parapharmacyCompany->id,
            'name' => 'Vitamin D',
            'sku' => 'VIT-D-001',
        ]);

        $response = $this->actingAs($parapharmacyUser, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonMissing(['automotive_metadata']);
    }

    /** @test */
    public function it_validates_automotive_metadata_enum_values(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'TEST-ENUM',
                'type' => 'part',
                'automotive_metadata' => [
                    'article_status' => 'invalid_status',
                    'brand_quality_tier' => 'invalid_tier',
                ],
            ]);

        $this->assertApiValidationErrors($response, [
            'automotive_metadata.article_status',
            'automotive_metadata.brand_quality_tier',
        ]);
    }

    /** @test */
    public function it_validates_automotive_metadata_nested_structure(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'TEST-NESTED',
                'type' => 'part',
                'automotive_metadata' => [
                    'cross_references' => [
                        ['reference_number' => 'REF-001'], // Missing reference_type
                    ],
                    'vehicles' => [
                        ['vehicle_display' => 'Some Vehicle'], // Missing vehicle_type
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, [
            'automotive_metadata.cross_references.0.reference_type',
            'automotive_metadata.vehicles.0.vehicle_type',
        ]);
    }

    /** @test */
    public function it_creates_tire_specific_metadata(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Michelin Pilot Sport 4',
                'sku' => 'TIRE-MPS4-225',
                'type' => 'part',
                'sale_price' => 185.00,
                'automotive_metadata' => [
                    'article_number' => 'MPS4-225-45-R17',
                    'supplier_brand' => 'Michelin',
                    'product_group_name' => 'Tires',
                    'tire_width' => 225,
                    'tire_aspect_ratio' => 45,
                    'tire_rim_diameter' => 17,
                    'tire_speed_rating' => 'W',
                    'tire_load_index' => 91,
                    'tire_season' => 'summer',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.automotive_metadata.tire_width', 225)
            ->assertJsonPath('data.automotive_metadata.tire_aspect_ratio', 45)
            ->assertJsonPath('data.automotive_metadata.tire_rim_diameter', 17)
            ->assertJsonPath('data.automotive_metadata.tire_speed_rating', 'W')
            ->assertJsonPath('data.automotive_metadata.tire_load_index', 91)
            ->assertJsonPath('data.automotive_metadata.tire_season', 'summer');

        $this->assertDatabaseHas('automotive_product_metadata', [
            'tire_width' => 225,
            'tire_aspect_ratio' => 45,
            'tire_rim_diameter' => 17,
            'tire_speed_rating' => 'W',
            'tire_load_index' => 91,
            'tire_season' => 'summer',
        ]);
    }

    /** @test */
    public function it_creates_glass_specific_metadata(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Windshield VW Golf VII',
                'sku' => 'GLASS-WS-GOLF7',
                'type' => 'part',
                'sale_price' => 320.00,
                'automotive_metadata' => [
                    'article_number' => 'WS-GOLF7-001',
                    'supplier_brand' => 'Pilkington',
                    'product_group_name' => 'Car Glass',
                    'glass_type' => 'windshield',
                    'glass_tinting' => 'clear',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.automotive_metadata.glass_type', 'windshield')
            ->assertJsonPath('data.automotive_metadata.glass_tinting', 'clear');

        $this->assertDatabaseHas('automotive_product_metadata', [
            'glass_type' => 'windshield',
            'glass_tinting' => 'clear',
        ]);
    }
}
