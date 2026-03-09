<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Marketplace\Application\Services\ListingSyncService;
use App\Modules\Marketplace\Domain\Enums\ListingStatus;
use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private MarketplaceSeller $seller;

    private ListingSyncService $syncService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Seller Tenant',
            'slug' => 'seller-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::PartsRetailer,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parts Shop',
            'legal_name' => 'Parts Shop LLC',
            'tax_id' => 'TAX999',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->seller = MarketplaceSeller::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'seller_status' => SellerStatus::Active,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->syncService = app(ListingSyncService::class);
    }

    private function createStockForProduct(Product $product, float $quantity = 100.00): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse',
            'type' => 'warehouse',
            'code' => 'WH-'.substr(md5($product->id), 0, 6),
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'reserved' => 0.00,
        ]);
    }

    /** @test */
    public function it_syncs_active_product_to_listing(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Filter',
            'sku' => 'OIL-001',
            'sale_price' => 25.500,
            'is_active' => true,
            'is_physical' => true,
        ]);

        $this->createStockForProduct($product);
        $this->syncService->syncProduct($this->seller, $product);

        $this->assertDatabaseHas('marketplace_listings', [
            'seller_id' => $this->seller->id,
            'source_product_id' => $product->id,
            'product_name' => 'Oil Filter',
            'price' => 25.500,
        ]);
    }

    /** @test */
    public function it_delists_inactive_product(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
            'is_physical' => true,
            'sale_price' => 20.000,
        ]);

        // First sync creates the listing
        $this->createStockForProduct($product);
        $this->syncService->syncProduct($this->seller, $product);
        $this->assertDatabaseHas('marketplace_listings', [
            'source_product_id' => $product->id,
            'listing_status' => ListingStatus::Active->value,
        ]);

        // Deactivate product
        $product->update(['is_active' => false]);

        // Resync should delist
        $this->syncService->syncProduct($this->seller, $product);
        $this->assertDatabaseHas('marketplace_listings', [
            'source_product_id' => $product->id,
            'listing_status' => ListingStatus::Delisted->value,
        ]);
    }

    /** @test */
    public function it_delists_product_without_price(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
            'is_physical' => true,
            'sale_price' => null,
        ]);

        // Create listing manually first
        $listing = MarketplaceListing::factory()->create([
            'seller_id' => $this->seller->id,
            'source_product_id' => $product->id,
            'listing_status' => ListingStatus::Active,
        ]);

        $this->syncService->syncProduct($this->seller, $product);

        $this->assertDatabaseHas('marketplace_listings', [
            'id' => $listing->id,
            'listing_status' => ListingStatus::Delisted->value,
        ]);
    }

    /** @test */
    public function it_updates_existing_listing_on_price_change(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
            'is_physical' => true,
            'sale_price' => 30.000,
        ]);

        // First sync
        $this->createStockForProduct($product);
        $this->syncService->syncProduct($this->seller, $product);

        $this->assertDatabaseHas('marketplace_listings', [
            'source_product_id' => $product->id,
            'price' => 30.000,
        ]);

        // Update price
        $product->update(['sale_price' => 35.500]);

        // Resync
        $this->syncService->syncProduct($this->seller, $product);

        // Should update existing listing, not create new
        $listings = MarketplaceListing::where('source_product_id', $product->id)->get();
        $this->assertCount(1, $listings);
        $this->assertEquals('35.500', (string) $listings->firstOrFail()->price);
    }
}
