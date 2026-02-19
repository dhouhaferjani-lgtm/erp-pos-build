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
        $cacheKey = "tenant_config:{$tenant->id}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($tenant) {
            // Get vertical enum from tenant (already cast to enum by Eloquent)
            $vertical = $tenant->vertical instanceof Vertical
                ? $tenant->vertical
                : Vertical::from($tenant->vertical);

            // Get vertical configuration
            $defaultModules = $this->verticalConfigService->getDefaultModules($vertical);

            // Decode enabled extras from JSON
            $enabledExtras = $this->decodeExtras($tenant->enabled_extras);

            // Merge default modules with enabled extras
            $allEnabledModules = array_unique(array_merge($defaultModules, $enabledExtras));

            return CompanyConfig::fromArray([
                'vertical' => $vertical,
                'default_modules' => $defaultModules,
                'enabled_extras' => $enabledExtras,
                'all_enabled_modules' => $allEnabledModules,
            ]);
        });
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
