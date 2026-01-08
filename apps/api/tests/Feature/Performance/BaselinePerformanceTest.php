<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Performance Baseline Test
 *
 * Establishes baseline performance metrics for key operations:
 * - Dashboard stats query
 * - Product list with pagination
 * - Stock level queries
 * - Invoice creation
 *
 * Run with: php artisan test --filter=BaselinePerformanceTest
 */
class BaselinePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Performance Test Tenant',
            'slug' => 'perf-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Performance Test Company',
            'legal_name' => 'Performance Test Company LLC',
            'tax_id' => 'PERF123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Performance Test User',
            'email' => 'perftest@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->user->givePermissionTo([
            'products.view',
            'products.create',
            'partners.view',
            'partners.create',
            'invoices.view',
            'invoices.create',
            'inventory.view',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-PERF',
            'name' => 'Performance Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    public function test_baseline_dashboard_load_performance(): void
    {
        // Create minimal test data
        $this->createTestPartners(10);

        $start = microtime(true);
        $response = $this->actingAs($this->user)->getJson('/api/v1/dashboard/stats');
        $duration = (microtime(true) - $start) * 1000; // Convert to ms

        $response->assertStatus(200);

        // Assert performance baseline: dashboard should load in under 1 second
        $this->assertLessThan(1000, $duration,
            "Dashboard stats took {$duration}ms (baseline: <1000ms)"
        );

        $this->reportMetric('Dashboard Stats Load Time', $duration, 'ms');
    }

    public function test_baseline_product_list_performance_100_items(): void
    {
        // Create 100 products
        $this->createTestProducts(100);

        $start = microtime(true);
        $response = $this->actingAs($this->user)->getJson('/api/v1/products?per_page=50');
        $duration = (microtime(true) - $start) * 1000;

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(50, count($response->json('data')));

        // Assert performance baseline: 100 products paginated should load in under 500ms
        $this->assertLessThan(500, $duration,
            "Product list (100 items) took {$duration}ms (baseline: <500ms)"
        );

        $this->reportMetric('Product List 100 Items (50/page)', $duration, 'ms');
    }

    public function test_baseline_stock_level_query_performance(): void
    {
        // Create 50 products with stock levels
        $products = $this->createTestProducts(50);

        foreach ($products as $product) {
            StockLevel::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'product_id' => $product->id,
                'location_id' => $this->location->id,
                'quantity' => '100.00',
                'reserved' => '10.00',
            ]);
        }

        $start = microtime(true);
        $stockLevels = StockLevel::with('product', 'location')->get();
        $duration = (microtime(true) - $start) * 1000;

        $this->assertCount(50, $stockLevels);

        // Assert performance baseline: 50 stock levels with relations should load in under 200ms
        $this->assertLessThan(200, $duration,
            "Stock level query (50 items) took {$duration}ms (baseline: <200ms)"
        );

        $this->reportMetric('Stock Level Query (50 items with relations)', $duration, 'ms');
    }

    public function test_baseline_invoice_creation_performance(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
            'email' => 'partner@example.com',
        ]);

        $invoiceData = [
            'partner_id' => $partner->id,
            'document_date' => '2025-01-15',
            'due_date' => '2025-02-15',
            'lines' => [
                [
                    'description' => 'Test Service 1',
                    'quantity' => '1.00',
                    'unit_price' => '100.00',
                    'tax_rate' => '20.00',
                ],
                [
                    'description' => 'Test Service 2',
                    'quantity' => '2.00',
                    'unit_price' => '50.00',
                    'tax_rate' => '20.00',
                ],
            ],
        ];

        $start = microtime(true);
        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', $invoiceData);
        $duration = (microtime(true) - $start) * 1000;

        $response->assertStatus(201);

        // Assert performance baseline: invoice creation should complete in under 500ms
        $this->assertLessThan(500, $duration,
            "Invoice creation took {$duration}ms (baseline: <500ms)"
        );

        $this->reportMetric('Invoice Creation (2 lines)', $duration, 'ms');
    }

    public function test_baseline_partner_list_performance_100_items(): void
    {
        $this->createTestPartners(100);

        $start = microtime(true);
        $response = $this->actingAs($this->user)->getJson('/api/v1/partners?per_page=50');
        $duration = (microtime(true) - $start) * 1000;

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(50, count($response->json('data')));

        // Assert performance baseline: 100 partners paginated should load in under 300ms
        $this->assertLessThan(300, $duration,
            "Partner list (100 items) took {$duration}ms (baseline: <300ms)"
        );

        $this->reportMetric('Partner List 100 Items (50/page)', $duration, 'ms');
    }

    /**
     * Create test products
     */
    private function createTestProducts(int $count): array
    {
        $products = [];

        for ($i = 1; $i <= $count; $i++) {
            $products[] = Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => sprintf('PROD-%04d', $i),
                'sku' => sprintf('SKU-%04d', $i),
                'name' => "Product {$i}",
                'type' => ProductType::Part,
                'unit_price' => sprintf('%.2f', rand(10, 1000)),
                'is_active' => true,
                'is_physical' => true,
            ]);
        }

        return $products;
    }

    /**
     * Create test partners
     */
    private function createTestPartners(int $count): array
    {
        $partners = [];

        for ($i = 1; $i <= $count; $i++) {
            $partners[] = Partner::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'name' => "Partner {$i}",
                'type' => PartnerType::Customer,
                'email' => "partner{$i}@example.com",
            ]);
        }

        return $partners;
    }

    /**
     * Report performance metric (for visibility in test output)
     */
    private function reportMetric(string $operation, float $duration, string $unit): void
    {
        // Output to console via assertion message
        $this->assertTrue(true,
            "✓ {$operation}: {$duration}{$unit}"
        );
    }
}
