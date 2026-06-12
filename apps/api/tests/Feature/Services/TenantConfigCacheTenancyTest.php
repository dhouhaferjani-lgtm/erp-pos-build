<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\Vertical;
use App\Models\VerticalConfig;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use App\Services\VerticalConfigService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Facades\GlobalCache;
use Tests\TestCase;

/**
 * Regression: tenant-config caches must be tenancy-NEUTRAL.
 *
 * In db-per-tenant production, CacheTenancyBootstrapper (config/tenancy.php)
 * swaps the Cache facade to Stancl's tagging CacheManager while tenancy is
 * initialized: every read/write through `Cache::` gets a `tenant<id>` tag.
 * Tenant-context reads (RequireModule -> CompanyConfigService /
 * VerticalConfigService) therefore stored TAGGED entries, while admin-side
 * invalidation (TenantObserver, VerticalConfigObserver, the admin controller)
 * runs in CENTRAL context and issued UNTAGGED forgets — which miss the tagged
 * copies, leaving module changes invisible to gating/sidebar for up to the
 * 24h TTL. The fix routes both services through Stancl's GlobalCache, which
 * is never swapped by the bootstrapper (keys already embed tenant id /
 * vertical, so isolation is preserved by key).
 *
 * Reproducing the production topology in the shared-DB harness requires two
 * pieces of scaffolding (without them the bug is invisible — which is exactly
 * why the regular suite stayed green):
 *
 *  1. The cache bootstrapper must actually FIRE. The app's
 *     TenancyServiceProvider gates BootstrapTenancy on
 *     `tenancy_resolver.db_per_tenant`, so we opt in — but list ONLY
 *     CacheTenancyBootstrapper so the DB/filesystem/queue bootstrappers leave
 *     the shared sqlite harness alone.
 *
 *  2. A SHARED taggable backing store. In production every manager (central,
 *     tenant-swapped, global) talks to the same Redis. The harness `array`
 *     driver instead news up a PRIVATE ArrayStore per CacheManager instance,
 *     and the bootstrapper builds a fresh Stancl CacheManager on every
 *     initialize() — so with the stock array driver a stale tagged entry
 *     simply evaporates between tenancy transitions and neither the bug nor
 *     the fix is observable. We register a custom `shared-array` driver
 *     backed by ONE ArrayStore on every manager the test touches (ArrayStore
 *     is taggable, so Stancl's tagging path is exercised for real), and pin
 *     a single globalCache manager via GlobalCache::swap() because Stancl
 *     binds it non-singleton and the bootstrapper clears facade instances on
 *     every transition. The "cache survives transitions" control test below
 *     proves this scaffolding works — i.e. a green run is not the cache
 *     silently evaporating.
 */
class TenantConfigCacheTenancyTest extends TestCase
{
    use RefreshDatabase;

    private ArrayStore $sharedStore;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy_resolver.db_per_tenant' => true,
            'tenancy.bootstrappers' => [CacheTenancyBootstrapper::class],
            'cache.stores.shared-array' => ['driver' => 'shared-array'],
            'cache.default' => 'shared-array',
        ]);

        $this->sharedStore = new ArrayStore;
        $this->registerSharedDriverOnCurrentCacheManager();

        $pinnedGlobalCache = new CacheManager(app());
        $this->registerSharedDriver($pinnedGlobalCache);
        GlobalCache::swap($pinnedGlobalCache);

        // CoffeeShop compatible extras: ['Tables', 'Loyalty', 'Inventory'].
        $this->tenant = Tenant::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Cache Topology Tenant',
            'slug' => 'cache-topology-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => [],
        ]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * Control: the shared-store scaffolding gives the cache Redis-like
     * durability across tenancy transitions. A change made WITHOUT firing
     * the invalidation path must keep serving the cached (stale) value —
     * otherwise the regression tests below would pass vacuously because the
     * cache evaporated rather than because invalidation reached it.
     */
    public function test_tenant_config_cache_survives_tenancy_transitions_without_invalidation(): void
    {
        $service = app(CompanyConfigService::class);

        $this->initializeTenancyWithSharedCache();
        $this->assertSame([], $service->getConfigForTenant($this->tenant)->enabledExtras);
        tenancy()->end();

        // Bypass TenantObserver — no invalidation fires.
        $silent = Tenant::query()->findOrFail($this->tenant->id);
        $silent->enabled_extras = ['Tables'];
        $silent->saveQuietly();

        $fresh = Tenant::query()->findOrFail($this->tenant->id);
        $this->initializeTenancyWithSharedCache();

        $this->assertSame(
            [],
            $service->getConfigForTenant($fresh)->enabledExtras,
            'Cached config must persist across tenancy transitions (shared backing store) — '
            .'if this fails the harness cannot prove anything about invalidation.'
        );
    }

    /**
     * THE regression: an admin-side (central-context) extras update goes
     * through TenantObserver -> CompanyConfigService::invalidateForTenant.
     * That forget must reach the entry written by a tenant-context read,
     * or gating/sidebar serve the old module set until the 24h TTL.
     */
    public function test_admin_extras_update_is_visible_to_tenant_context_reads(): void
    {
        $service = app(CompanyConfigService::class);

        // Prime the cache from INSIDE tenant context (RequireModule topology).
        $this->initializeTenancyWithSharedCache();
        $this->assertSame([], $service->getConfigForTenant($this->tenant)->enabledExtras);
        tenancy()->end();

        // Admin-side update in central context: fires TenantObserver::updated
        // -> invalidateForTenant.
        $central = Tenant::query()->findOrFail($this->tenant->id);
        $central->enabled_extras = ['Tables'];
        $central->save();

        $fresh = Tenant::query()->findOrFail($this->tenant->id);
        $this->initializeTenancyWithSharedCache();

        $this->assertSame(
            ['Tables'],
            $service->getConfigForTenant($fresh)->enabledExtras,
            'Tenant-context read must see the admin-side extras change after invalidation.'
        );
    }

    /**
     * Same bug, second cache layer: a central vertical_configs write (admin
     * controller / observer) must invalidate the per-vertical override entry
     * that a tenant-context read cached.
     */
    public function test_central_vertical_config_write_is_visible_to_tenant_context_reads(): void
    {
        $service = app(VerticalConfigService::class);
        $configDefaults = config('verticals.coffee_shop.default_modules');
        $this->assertIsArray($configDefaults);

        // Prime the override cache from INSIDE tenant context (no DB row →
        // config-file fallback, cached via sentinel).
        $this->initializeTenancyWithSharedCache();
        $this->assertSame($configDefaults, $service->getDefaultModules(Vertical::CoffeeShop));
        tenancy()->end();

        // Central-context write: VerticalConfigObserver::saved fires
        // invalidateVertical.
        VerticalConfig::query()->create([
            'vertical' => Vertical::CoffeeShop->value,
            'default_modules' => ['Identity', 'Tenant', 'Catalog'],
            'compatible_extras' => ['Loyalty'],
        ]);

        $this->initializeTenancyWithSharedCache();

        $this->assertSame(
            ['Identity', 'Tenant', 'Catalog'],
            $service->getDefaultModules(Vertical::CoffeeShop),
            'Tenant-context read must see the central vertical-config override after invalidation.'
        );
    }

    /**
     * Initialize tenancy, then hand the freshly-swapped tenant cache manager
     * the shared backing store (production-Redis stand-in — see class doc).
     */
    private function initializeTenancyWithSharedCache(): void
    {
        tenancy()->initialize($this->tenant);

        $this->registerSharedDriverOnCurrentCacheManager();
    }

    private function registerSharedDriverOnCurrentCacheManager(): void
    {
        $manager = app('cache');
        $this->assertInstanceOf(CacheManager::class, $manager);

        $this->registerSharedDriver($manager);
    }

    private function registerSharedDriver(CacheManager $manager): void
    {
        $store = $this->sharedStore;

        $manager->extend('shared-array', fn (): Repository => new Repository($store));
    }
}
