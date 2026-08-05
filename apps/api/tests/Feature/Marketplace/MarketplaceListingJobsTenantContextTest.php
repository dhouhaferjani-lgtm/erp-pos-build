<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Marketplace\Application\Services\ListingSyncService;
use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Marketplace\Infrastructure\Jobs\ReconcileListingsJob;
use App\Modules\Marketplace\Infrastructure\Jobs\SyncSellerListingsJob;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Finding B closure (2026-08-05): {@see SyncSellerListingsJob} and
 * {@see ReconcileListingsJob} now carry the dispatching tenant on their queue
 * payload and rebind it via {@see BindsTenantContext::withTenantContext()}
 * before any data access, instead of relying solely on
 * QueueTenancyBootstrapper having stamped the payload at dispatch time.
 *
 * What this buys: the bootstrapper's stamp only exists when the dispatch
 * happened under initialized tenancy AND the payload survived intact. Any path
 * that re-hydrates one of these jobs from CENTRAL context — a manual re-queue
 * of a `failed_jobs` row, a synchronous dispatch from a console context, a
 * `queue:retry` — would previously run `MarketplaceSeller::find()` against the
 * central database. The explicit tenant id makes the binding self-sufficient
 * and fail-loud.
 *
 * NOT changed, deliberately: the seller lookup is still
 * `MarketplaceSeller::find($this->sellerId)` with NO `where('tenant_id', …)`
 * predicate. `marketplace_sellers.tenant_id` is NULLABLE — external /
 * Synerivia-owned sellers carry NULL (see MarketplaceSellerFactory::external())
 * — so the tenant_id predicate recommended by the audit's Finding B would
 * silently drop exactly those sellers. Isolation comes from the physically
 * separate tenant database that withTenantContext() binds, not from a WHERE
 * clause. This test pins BOTH halves: the binding must happen, and an
 * external (tenant_id = NULL) seller must still be synced.
 */
final class MarketplaceListingJobsTenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_sync_seller_listings_job_binds_dispatched_tenant_before_touching_data(): void
    {
        [$tenant, $seller, $product] = $this->createSellerWithProduct('sync-binds');

        $recorder = new TenantContextRecordingListingSyncService;

        (new SyncSellerListingsJob($seller->id, $tenant->id))->handle($recorder);

        self::assertSame(
            [[
                'seller_id' => $seller->id,
                'product_id' => $product->id,
                'tenancy_initialized' => true,
                'bound_tenant_id' => $tenant->id,
            ]],
            $recorder->calls,
            'The sync must run inside the dispatched tenant context, not whatever context the worker happened to be in.',
        );

        self::assertFalse(tenancy()->initialized, 'The job must revert the worker to central context when it finishes.');
        self::assertNotNull($seller->refresh()->last_sync_at);
    }

    public function test_reconcile_listings_job_binds_dispatched_tenant_before_touching_data(): void
    {
        [$tenant, $seller, $product] = $this->createSellerWithProduct('reconcile-binds');

        $recorder = new TenantContextRecordingListingSyncService;

        (new ReconcileListingsJob($seller->id, $tenant->id))->handle($recorder);

        self::assertSame(
            [[
                'seller_id' => $seller->id,
                'product_id' => $product->id,
                'tenancy_initialized' => true,
                'bound_tenant_id' => $tenant->id,
            ]],
            $recorder->calls,
            'The reconciliation must run inside the dispatched tenant context.',
        );

        self::assertFalse(tenancy()->initialized);
        self::assertNotNull($seller->refresh()->last_sync_at);
    }

    public function test_sync_seller_listings_job_fails_loud_when_dispatched_tenant_is_gone(): void
    {
        [, $seller] = $this->createSellerWithProduct('sync-fail-loud');
        $missingTenantId = (string) Str::uuid();

        $recorder = new TenantContextRecordingListingSyncService;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($missingTenantId, '/').'/');

        try {
            (new SyncSellerListingsJob($seller->id, $missingTenantId))->handle($recorder);
        } finally {
            self::assertSame([], $recorder->calls, 'No data may be touched when the tenant cannot be resolved.');
            self::assertNull($seller->refresh()->last_sync_at);
        }
    }

    public function test_reconcile_listings_job_fails_loud_when_dispatched_tenant_is_gone(): void
    {
        [, $seller] = $this->createSellerWithProduct('reconcile-fail-loud');
        $missingTenantId = (string) Str::uuid();

        $recorder = new TenantContextRecordingListingSyncService;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($missingTenantId, '/').'/');

        try {
            (new ReconcileListingsJob($seller->id, $missingTenantId))->handle($recorder);
        } finally {
            self::assertSame([], $recorder->calls);
            self::assertNull($seller->refresh()->last_sync_at);
        }
    }

    /**
     * The external-seller leg: `marketplace_sellers.tenant_id` is NULL for
     * Synerivia-owned sellers. Binding the job to the DISPATCHING tenant (the
     * database the seller row lives in) must still sync them — which is why
     * the seller lookup stays unscoped.
     */
    public function test_external_seller_with_null_tenant_id_is_still_synced_under_the_dispatching_tenant(): void
    {
        $tenant = $this->createTenant('external-seller-tenant');
        $company = $this->createCompany($tenant, 'EXT');

        $seller = MarketplaceSeller::factory()->external()->create([
            'seller_status' => SellerStatus::Active,
            'company_id' => $company->id,
            'display_name' => 'External Seller',
        ]);
        self::assertNull($seller->tenant_id);

        $product = $this->createProduct($tenant, $company, 'EXT-001');

        $recorder = new TenantContextRecordingListingSyncService;

        (new SyncSellerListingsJob($seller->id, $tenant->id))->handle($recorder);

        self::assertSame(
            [[
                'seller_id' => $seller->id,
                'product_id' => $product->id,
                'tenancy_initialized' => true,
                'bound_tenant_id' => $tenant->id,
            ]],
            $recorder->calls,
            'A tenant_id-scoped seller lookup would silently drop external sellers — it must NOT be added.',
        );
    }

    /**
     * @return array{0: Tenant, 1: MarketplaceSeller, 2: Product}
     */
    private function createSellerWithProduct(string $slug): array
    {
        $tenant = $this->createTenant($slug);
        $company = $this->createCompany($tenant, Str::upper(Str::random(4)));

        $seller = MarketplaceSeller::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'seller_status' => SellerStatus::Active,
            'last_sync_at' => null,
        ]);

        $product = $this->createProduct($tenant, $company, 'SKU-'.Str::upper(Str::random(6)));

        return [$tenant, $seller, $product];
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function createCompany(Tenant $tenant, string $suffix): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Listing Jobs Company {$suffix}",
            'legal_name' => "Listing Jobs Company {$suffix} LLC",
            'tax_id' => "TAX-LJ-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createProduct(Tenant $tenant, Company $company, string $sku): Product
    {
        return Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Listable Product',
            'sku' => $sku,
            'sale_price' => '25.500',
            'is_active' => true,
            'is_physical' => true,
        ]);
    }
}

/**
 * Records the tenancy state observed at the moment the job hands a product to
 * the sync service — the only observation point that proves the binding
 * happened BEFORE the job's own queries, not after.
 */
final class TenantContextRecordingListingSyncService extends ListingSyncService
{
    /** @var list<array{seller_id: string, product_id: string, tenancy_initialized: bool, bound_tenant_id: string|null}> */
    public array $calls = [];

    public function syncProduct(MarketplaceSeller $seller, Product $product): void
    {
        $bound = tenancy()->tenant;

        $this->calls[] = [
            'seller_id' => $seller->id,
            'product_id' => $product->id,
            'tenancy_initialized' => tenancy()->initialized,
            'bound_tenant_id' => $bound instanceof Tenant ? $bound->id : null,
        ];
    }
}
