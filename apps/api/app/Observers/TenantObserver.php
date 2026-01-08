<?php

declare(strict_types=1);

namespace App\Observers;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Observer for Tenant model to handle cache invalidation.
 *
 * When a tenant's vertical or enabled_extras changes, we need to invalidate
 * the tenant config cache.
 *
 * This ensures that CompanyConfigService will rebuild the configuration
 * with the new vertical settings on the next request.
 *
 * Note: All companies within a tenant share the same configuration (no
 * company-level vertical overrides), so we only need to invalidate one
 * cache entry per tenant.
 */
class TenantObserver
{
    /**
     * Handle the Tenant "updated" event.
     *
     * Invalidates tenant config cache when vertical or enabled_extras changes.
     */
    public function updated(Tenant $tenant): void
    {
        // Check if vertical or enabled_extras changed
        if ($tenant->wasChanged(['vertical', 'enabled_extras'])) {
            $this->invalidateTenantConfigCache($tenant);
        }
    }

    /**
     * Invalidate tenant config cache.
     *
     * Since all companies within a tenant share the same vertical and extras,
     * we only need to invalidate a single cache entry per tenant.
     */
    private function invalidateTenantConfigCache(Tenant $tenant): void
    {
        $cacheKey = "tenant_config:{$tenant->id}";
        Cache::forget($cacheKey);
    }
}
