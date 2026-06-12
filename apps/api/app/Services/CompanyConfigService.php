<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CompanyConfig;
use App\Enums\Vertical;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Service for managing company effective configuration
 *
 * This service provides access to a company's effective configuration
 * by combining vertical defaults with company-specific enabled extras.
 *
 * Configuration is cached per tenant for 24 hours since all companies
 * within a tenant share the same vertical and enabled extras.
 */
class CompanyConfigService
{
    private const CACHE_TTL_SECONDS = 86400; // 24 hours

    private const CACHE_KEY_PREFIX = 'tenant_config:';

    public function __construct(
        private readonly VerticalConfigService $verticalConfigService
    ) {}

    /**
     * Get effective configuration for a tenant (with 24-hour caching)
     *
     * Combines vertical defaults with tenant-specific enabled extras.
     * Cached per tenant since all companies within a tenant share the same config.
     */
    public function getConfigForTenant(Tenant $tenant): CompanyConfig
    {
        $cacheKey = $this->cacheKeyForTenant($tenant->id);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($tenant) {
            // Get vertical enum from tenant (already cast to Vertical enum by Eloquent)
            $vertical = $tenant->vertical;

            // Get vertical configuration
            $defaultModules = $this->verticalConfigService->getDefaultModules($vertical);

            // Get all optional modules supported by this vertical
            $compatibleExtras = $this->verticalConfigService->getCompatibleExtras($vertical);

            // Decode enabled extras from JSON
            $enabledExtras = $this->decodeExtras($tenant->enabled_extras);

            // Merge default modules with enabled extras
            $allEnabledModules = array_unique(array_merge($defaultModules, $enabledExtras));

            return CompanyConfig::fromArray([
                'vertical' => $vertical,
                'default_modules' => $defaultModules,
                'enabled_extras' => $enabledExtras,
                'compatible_extras' => $compatibleExtras,
                'all_enabled_modules' => $allEnabledModules,
            ]);
        });
    }

    /**
     * Invalidate the cached effective configuration for one tenant.
     *
     * Single owner of the tenant_config cache key — every invalidation
     * path (observer, admin fanout) must go through this service.
     */
    public function invalidateForTenant(string $tenantId): void
    {
        Cache::forget($this->cacheKeyForTenant($tenantId));
    }

    /**
     * Invalidate the cached effective configuration for every tenant of a vertical.
     *
     * Used when a central per-vertical override changes: each tenant on the
     * vertical caches its merged config for 24h, so the change would otherwise
     * only surface at TTL expiry.
     *
     * Note: forget-based invalidation has an inherent in-flight-read race (a
     * read started before the forget can re-cache stale data) that self-heals
     * at TTL.
     */
    public function invalidateForVertical(Vertical $vertical): void
    {
        Tenant::query()
            ->where('vertical', $vertical->value)
            ->select('id')
            ->pluck('id')
            ->each(function (string $tenantId): void {
                $this->invalidateForTenant($tenantId);
            });
    }

    /**
     * Build the cache key for a tenant's effective configuration.
     */
    private function cacheKeyForTenant(string $tenantId): string
    {
        return self::CACHE_KEY_PREFIX.$tenantId;
    }

    /**
     * Decode extras from JSON string or array
     *
     * @param  string|array<int, string>|null  $extras
     * @return array<int, string>
     */
    private function decodeExtras(string|array|null $extras): array
    {
        if ($extras === null || $extras === '') {
            return [];
        }

        if (is_array($extras)) {
            return $extras;
        }

        $decoded = json_decode($extras, true);

        return is_array($decoded) ? $decoded : [];
    }
}
