<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Marketplace\Application\Listeners\SyncListingOnPriceChange;
use App\Modules\Marketplace\Application\Listeners\SyncListingOnStockChange;
use App\Modules\Marketplace\Application\Services\ListingSyncService;
use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cluster regression tests for api.marketplace tenant-isolation.
 *
 * Both listeners (SyncListingOnPriceChange + SyncListingOnStockChange)
 * previously did `Product::query()->find($event->productId)` and
 * `MarketplaceSeller::where('company_id', X)` without tenant scoping.
 * A forged event payload with a foreign productId could resolve a
 * Product from another tenant — exfiltrating its name/sku into the
 * marketplace listing layer. The fix scopes both lookups by the event's
 * companyId and the resolved product's tenant_id.
 *
 * Tests use a recording fake instead of Mockery to satisfy phpstan
 * level 8 (no MockInterface coercion + every assertion is non-trivial).
 */
class MarketplaceTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private MarketplaceSeller $sellerA;

    private Product $productA;

    private Product $productB;

    private RecordingListingSyncService $syncService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-mp-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::PartsRetailer,
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-mp-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::PartsRetailer,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-MP',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-MP',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->sellerA = MarketplaceSeller::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'seller_status' => SellerStatus::Active,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        $this->productA = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Product A',
            'sku' => 'A-001',
            'sale_price' => 10.000,
            'is_active' => true,
            'is_physical' => true,
        ]);

        $this->productB = Product::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Product B',
            'sku' => 'B-001',
            'sale_price' => 99.999,
            'is_active' => true,
            'is_physical' => true,
        ]);

        $this->syncService = new RecordingListingSyncService;
    }

    /** @test */
    public function sync_on_stock_change_rejects_event_with_cross_company_product_id(): void
    {
        $listener = new SyncListingOnStockChange($this->syncService);

        // Forged event: companyA's company_id but productB's id (cross-tenant).
        $event = new class
        {
            public string $productId;

            public string $companyId;
        };
        $event->productId = $this->productB->id;
        $event->companyId = $this->companyA->id;

        $listener->handle($event);

        $this->assertSame(
            0,
            count($this->syncService->calls),
            'syncProduct must not be called when event payload mixes companies.',
        );
    }

    /** @test */
    public function sync_on_stock_change_resolves_when_event_company_matches_product_company(): void
    {
        $listener = new SyncListingOnStockChange($this->syncService);

        $event = new class
        {
            public string $productId;

            public string $companyId;
        };
        $event->productId = $this->productA->id;
        $event->companyId = $this->companyA->id;

        $listener->handle($event);

        $this->assertCount(1, $this->syncService->calls);
        $call = $this->syncService->calls[0];
        $this->assertSame($this->sellerA->id, $call['seller_id']);
        $this->assertSame($this->productA->id, $call['product_id']);
    }

    /** @test */
    public function sync_on_price_change_requires_company_id_in_event_payload(): void
    {
        $listener = new SyncListingOnPriceChange($this->syncService);

        $event = new class
        {
            public string $productId;
        };
        $event->productId = $this->productA->id;

        $listener->handle($event);

        $this->assertSame(
            0,
            count($this->syncService->calls),
            'Listener must reject events missing companyId — defense-in-depth.',
        );
    }

    /** @test */
    public function sync_on_price_change_rejects_event_with_cross_company_product_id(): void
    {
        $listener = new SyncListingOnPriceChange($this->syncService);

        $event = new class
        {
            public string $productId;

            public string $companyId;
        };
        $event->productId = $this->productB->id;
        $event->companyId = $this->companyA->id;

        $listener->handle($event);

        $this->assertSame(
            0,
            count($this->syncService->calls),
            'syncProduct must not be called when event payload mixes companies.',
        );
    }
}

/**
 * Recording double for ListingSyncService — no mocking framework needed.
 *
 * Captures every syncProduct() invocation as a hashable record so tests
 * can assert call count + identity without phpstan's MockInterface coercion
 * complaints.
 */
final class RecordingListingSyncService extends ListingSyncService
{
    /** @var list<array{seller_id: string, product_id: string}> */
    public array $calls = [];

    public function __construct()
    {
        // Skip the parent constructor — this fake doesn't exercise dependencies.
    }

    public function syncProduct(MarketplaceSeller $seller, Product $product): void
    {
        $this->calls[] = [
            'seller_id' => $seller->id,
            'product_id' => $product->id,
        ];
    }
}
