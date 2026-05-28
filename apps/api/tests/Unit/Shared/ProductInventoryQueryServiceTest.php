<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\AutomotiveProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Infrastructure\Services\ProductInventoryQueryService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\DTOs\ProductInventoryDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductInventoryQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private ProductInventoryQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-inventory-query',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
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

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'code' => 'WH-MAIN',
        ]);

        $this->service = new ProductInventoryQueryService;
    }

    public function test_returns_inventory_dtos_keyed_by_platform_article_id(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '29.99',
        ]);

        // platform_article_id is a uuid column (PostgreSQL rejects non-UUID
        // strings); use a real UUID for the lookup key.
        $articleId = (string) Str::uuid();
        AutomotiveProductMetadata::factory()->create([
            'product_id' => $product->id,
            'platform_article_id' => $articleId,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '100.00',
            'reserved' => '10.00',
        ]);

        $results = $this->service->findByPlatformArticleIds(
            $this->company->id,
            [$articleId],
        );

        $this->assertCount(1, $results);
        $this->assertTrue($results->has($articleId));

        $dto = $results->get($articleId);
        $this->assertInstanceOf(ProductInventoryDTO::class, $dto);
        $this->assertSame($product->id, $dto->productId);
        $this->assertSame($articleId, $dto->platformArticleId);
        $this->assertEqualsWithDelta(100.0, (float) $dto->totalStock, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $dto->totalReserved, 0.01);
        $this->assertSame('90.00', $dto->available); // bcsub formats with scale 2
        $this->assertNotNull($dto->salePrice);
    }

    public function test_returns_empty_for_unmatched_article_ids(): void
    {
        $results = $this->service->findByPlatformArticleIds(
            $this->company->id,
            [(string) Str::uuid()],
        );

        $this->assertCount(0, $results);
    }

    public function test_scopes_to_company(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $articleId = (string) Str::uuid();
        AutomotiveProductMetadata::factory()->create([
            'product_id' => $product->id,
            'platform_article_id' => $articleId,
        ]);

        // Query with original company — should not find the other company's product
        $results = $this->service->findByPlatformArticleIds(
            $this->company->id,
            [$articleId],
        );

        $this->assertCount(0, $results);
    }

    public function test_aggregates_stock_across_multiple_locations(): void
    {
        $location2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Secondary Warehouse',
            'type' => 'warehouse',
            'code' => 'WH-SEC',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '50.00',
        ]);

        $articleId = (string) Str::uuid();
        AutomotiveProductMetadata::factory()->create([
            'product_id' => $product->id,
            'platform_article_id' => $articleId,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '60.00',
            'reserved' => '5.00',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $location2->id,
            'quantity' => '40.00',
            'reserved' => '15.00',
        ]);

        $results = $this->service->findByPlatformArticleIds(
            $this->company->id,
            [$articleId],
        );

        $dto = $results->get($articleId);
        $this->assertEqualsWithDelta(100.0, (float) $dto->totalStock, 0.01);    // 60 + 40
        $this->assertEqualsWithDelta(20.0, (float) $dto->totalReserved, 0.01);  // 5 + 15
        $this->assertSame('80.00', $dto->available);                              // 100 - 20 via bcsub
    }
}
