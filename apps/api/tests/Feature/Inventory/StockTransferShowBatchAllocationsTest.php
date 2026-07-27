<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferBatchAllocationData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The stock-transfer SHOW endpoint must expose FEFO batch allocations per line
 * (batch_number, expiry_date, quantity) so the detail page can render which
 * lots left the source. Lines for non-batch-tracked products expose an empty
 * `batch_allocations` array (present, not absent) so the frontend can rely on
 * a consistent shape.
 */
final class StockTransferShowBatchAllocationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Location $shop;

    private StockTransferService $service;

    private StockAdjustmentService $stockService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Show Alloc Tenant',
            'slug' => 'show-alloc-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Show Alloc Co',
            'legal_name' => 'Show Alloc Co LLC',
            'tax_id' => 'TAX-SHOW',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Show Alloc User',
            'email' => 'show-alloc@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'inventory.transfers.view',
            'inventory.transfers.create',
            'products.view',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
            'allowed_location_ids' => null,
            'status' => 'active',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-SHOW',
            'name' => 'Show Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->shop = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SH-SHOW',
            'name' => 'Show Shop',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->service = app(StockTransferService::class);
        $this->stockService = app(StockAdjustmentService::class);
    }

    public function test_show_returns_batch_allocations_for_batch_tracked_line(): void
    {
        $product = $this->createProduct('PROD-BATCH', requiresBatch: true);

        $early = $this->createBatch($product, 'LOT-EARLY', now()->addMonths(2)->toDateString());
        $late = $this->createBatch($product, 'LOT-LATE', now()->addMonths(9)->toDateString());
        $this->seedBatchStock($product, $early, '3.0000');
        $this->seedBatchStock($product, $late, '5.0000');

        $transfer = $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(
                    productId: $product->id,
                    quantity: '4.0000',
                    batchAllocations: [
                        new InitiateTransferBatchAllocationData(batchId: (int) $early->id, quantity: '3.0000'),
                        new InitiateTransferBatchAllocationData(batchId: (int) $late->id, quantity: '1.0000'),
                    ],
                ),
            ],
        ));

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/stock-transfers/{$transfer->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $transfer->id);

        $allocations = $response->json('data.lines.0.batch_allocations');
        $this->assertIsArray($allocations);
        $this->assertCount(2, $allocations);

        // Assert against the RAW response order — no client-side re-sorting —
        // so the API's earliest-expiry-first (FEFO) ordering is genuinely
        // pinned and a regression in ordering fails this test.
        $this->assertSame('LOT-EARLY', $allocations[0]['batch_number']);
        $this->assertSame($early->expiry_date->toDateString(), $allocations[0]['expiry_date']);
        $this->assertSame('3.0000', (string) $allocations[0]['quantity']);

        $this->assertSame('LOT-LATE', $allocations[1]['batch_number']);
        $this->assertSame($late->expiry_date->toDateString(), $allocations[1]['expiry_date']);
        $this->assertSame('1.0000', (string) $allocations[1]['quantity']);
    }

    public function test_show_returns_empty_batch_allocations_for_non_batch_line(): void
    {
        $product = $this->createProduct('PROD-PLAIN', requiresBatch: false);

        $this->stockService->receive(
            productId: $product->id,
            locationId: $this->warehouse->id,
            quantity: '5.0000',
            reference: 'SEED-PLAIN',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        $transfer = $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(productId: $product->id, quantity: '2.0000'),
            ],
        ));

        $this->actingAs($this->user)
            ->getJson("/api/v1/stock-transfers/{$transfer->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.lines.0.batch_allocations', []);
    }

    public function test_show_returns_line_quantity_decimals_from_the_product_unit(): void
    {
        $category = UnitCategory::factory()->create([
            'tenant_id' => null,
            'code' => 'transfer-weight',
            'name' => 'Transfer Weight',
            'is_system' => true,
            'is_active' => true,
        ]);
        $unit = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'transfer-kg',
            'name' => 'Transfer Kilogram',
            'symbol' => 'kg',
            'decimal_places' => 3,
            'is_system' => true,
            'is_active' => true,
        ]);
        $product = $this->createProduct('PROD-WEIGHT', requiresBatch: false, unit: $unit);

        $this->stockService->receive(
            productId: $product->id,
            locationId: $this->warehouse->id,
            quantity: '5.0000',
            reference: 'SEED-WEIGHT',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        $transfer = $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(productId: $product->id, quantity: '2.5000'),
            ],
        ));

        $this->actingAs($this->user)
            ->getJson("/api/v1/stock-transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', '2.5000')
            ->assertJsonPath('data.lines.0.quantity_decimals', 3);
    }

    private function createProduct(string $sku, bool $requiresBatch, ?Unit $unit = null): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $sku,
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => $requiresBatch,
            'unit_id' => $unit?->id,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);
    }

    private function createBatch(Product $product, string $batchNumber, string $expiryDate): Batch
    {
        /** @var Batch $batch */
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'manufacturing_date' => now()->subMonth()->toDateString(),
            'expiry_date' => $expiryDate,
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        return $batch;
    }

    /**
     * @param  numeric-string  $quantity
     */
    private function seedBatchStock(Product $product, Batch $batch, string $quantity): void
    {
        $this->stockService->receive(
            productId: $product->id,
            locationId: $this->warehouse->id,
            quantity: $quantity,
            reference: 'BATCH-SEED',
            userId: $this->user->id,
            batchId: (int) $batch->id,
            expectedCompanyId: $this->company->id,
        );
    }
}
