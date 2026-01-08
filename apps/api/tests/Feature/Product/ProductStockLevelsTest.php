<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductStockLevelsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location1;

    private Location $location2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        // Seed roles and permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create user
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        // Create user-company membership
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        // Set company context
        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Create locations
        $this->location1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
            'type' => \App\Modules\Company\Domain\Enums\LocationType::Warehouse,
            'is_active' => true,
        ]);

        $this->location2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
            'type' => \App\Modules\Company\Domain\Enums\LocationType::Warehouse,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_returns_stock_levels_with_totals(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
        ]);

        // Create stock levels
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location1->id,
            'quantity' => '100.00',
            'reserved' => '20.00',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location2->id,
            'quantity' => '50.00',
            'reserved' => '10.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}/stock-levels");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'locations' => [
                        '*' => [
                            'id',
                            'location_id',
                            'location_name',
                            'quantity',
                            'reserved',
                            'available',
                            'incoming',
                            'projected_available',
                        ],
                    ],
                    'totals' => [
                        'quantity',
                        'reserved',
                        'available',
                        'incoming',
                        'projected_available',
                    ],
                ],
            ]);

        $data = $response->json('data');

        // Assert totals
        $this->assertEquals('150.00', $data['totals']['quantity']);
        $this->assertEquals('30.00', $data['totals']['reserved']);
        $this->assertEquals('120.00', $data['totals']['available']);
        $this->assertEquals('0.00', $data['totals']['incoming']);
        $this->assertEquals('120.00', $data['totals']['projected_available']);

        // Assert locations count
        $this->assertCount(2, $data['locations']);
    }

    /** @test */
    public function it_calculates_incoming_stock_from_confirmed_purchase_orders(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
        ]);

        // Create stock level
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location1->id,
            'quantity' => '50.00',
            'reserved' => '0.00',
        ]);

        // Create supplier
        $supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'supplier',
        ]);

        // Create confirmed purchase order with pending receipt
        $purchaseOrder = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Confirmed,
            'partner_id' => $supplier->id,
        ]);

        // Create document line with partial receipt
        DocumentLine::create([
            'document_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'location_id' => $this->location1->id,
            'description' => 'Test Product',
            'quantity' => '100.00',
            'quantity_received' => '30.00', // 70 still pending
            'unit_price' => '10.00',
            'tax_rate' => '19.00',
            'line_total' => '1000.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}/stock-levels");

        $response->assertOk();

        $data = $response->json('data');

        // Assert incoming stock
        $this->assertEquals('70.00', $data['totals']['incoming']);
        $this->assertEquals('120.00', $data['totals']['projected_available']); // 50 available + 70 incoming
    }

    /** @test */
    public function it_excludes_draft_purchase_orders_from_incoming_stock(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location1->id,
            'quantity' => '50.00',
            'reserved' => '0.00',
        ]);

        $supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'supplier',
        ]);

        // Create DRAFT purchase order (should not count)
        $draftPO = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Draft,
            'partner_id' => $supplier->id,
        ]);

        DocumentLine::create([
            'document_id' => $draftPO->id,
            'product_id' => $product->id,
            'location_id' => $this->location1->id,
            'description' => 'Test Product',
            'quantity' => '100.00',
            'quantity_received' => '0.00',
            'unit_price' => '10.00',
            'tax_rate' => '19.00',
            'line_total' => '1000.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}/stock-levels");

        $response->assertOk();

        $data = $response->json('data');

        // Draft PO should NOT be included in incoming
        $this->assertEquals('0.00', $data['totals']['incoming']);
        $this->assertEquals('50.00', $data['totals']['projected_available']);
    }

    /** @test */
    public function it_excludes_fully_received_purchase_orders_from_incoming(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location1->id,
            'quantity' => '50.00',
            'reserved' => '0.00',
        ]);

        $supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'supplier',
        ]);

        $purchaseOrder = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Confirmed,
            'partner_id' => $supplier->id,
        ]);

        // Fully received line (should not count)
        DocumentLine::create([
            'document_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'location_id' => $this->location1->id,
            'description' => 'Test Product',
            'quantity' => '100.00',
            'quantity_received' => '100.00', // Fully received
            'unit_price' => '10.00',
            'tax_rate' => '19.00',
            'line_total' => '1000.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}/stock-levels");

        $response->assertOk();

        $data = $response->json('data');

        // Fully received should NOT be in incoming
        $this->assertEquals('0.00', $data['totals']['incoming']);
    }

    /** @test */
    public function it_groups_incoming_stock_by_location(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Stock at both locations
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location1->id,
            'quantity' => '50.00',
            'reserved' => '0.00',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location2->id,
            'quantity' => '30.00',
            'reserved' => '0.00',
        ]);

        $supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'supplier',
        ]);

        $purchaseOrder = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Confirmed,
            'partner_id' => $supplier->id,
        ]);

        // Incoming to location 1
        DocumentLine::create([
            'document_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'location_id' => $this->location1->id,
            'description' => 'Test Product',
            'quantity' => '100.00',
            'quantity_received' => '0.00',
            'unit_price' => '10.00',
            'tax_rate' => '19.00',
            'line_total' => '1000.00',
        ]);

        // Incoming to location 2
        DocumentLine::create([
            'document_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'location_id' => $this->location2->id,
            'description' => 'Test Product',
            'quantity' => '50.00',
            'quantity_received' => '0.00',
            'unit_price' => '10.00',
            'tax_rate' => '19.00',
            'line_total' => '500.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}/stock-levels");

        $response->assertOk();

        $data = $response->json('data');

        // Find each location's data
        $loc1Data = collect($data['locations'])->firstWhere('location_id', $this->location1->id);
        $loc2Data = collect($data['locations'])->firstWhere('location_id', $this->location2->id);

        // Assert per-location incoming
        $this->assertEquals('100.00', $loc1Data['incoming']);
        $this->assertEquals('150.00', $loc1Data['projected_available']); // 50 + 100

        $this->assertEquals('50.00', $loc2Data['incoming']);
        $this->assertEquals('80.00', $loc2Data['projected_available']); // 30 + 50

        // Assert total incoming
        $this->assertEquals('150.00', $data['totals']['incoming']);
    }

    /** @test */
    public function it_returns_404_for_non_existent_product(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products/non-existent-id/stock-levels');

        $response->assertNotFound();
    }

    /** @test */
    public function it_requires_authentication(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}/stock-levels");

        $response->assertUnauthorized();
    }

    /** @test */
    public function it_prevents_access_to_other_company_products(): void
    {
        // Create another company
        $otherCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$otherProduct->id}/stock-levels");

        $response->assertNotFound();
    }
}
