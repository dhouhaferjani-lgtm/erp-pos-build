<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Vertical;
use App\Models\VerticalConfig;
use Stancl\Tenancy\Facades\GlobalCache;

/**
 * Service for managing vertical configurations
 *
 * This service provides access to vertical-specific configurations
 * including labels, descriptions, compatible extras, and default modules.
 *
 * Module lists are DB-first: a `vertical_configs` row in the central
 * database overrides the config/verticals.php values per field — a null
 * DB field falls back to the config file value. The DB lookup is cached
 * for 24 hours (including the "no row" result, via a sentinel array).
 *
 * CACHE TOPOLOGY — GlobalCache, NOT the Cache facade. The override is read
 * from tenant context (RequireModule -> CompanyConfigService) but
 * invalidated from central context (VerticalConfigObserver / admin
 * controller). CacheTenancyBootstrapper swaps the Cache facade to Stancl's
 * tagging CacheManager inside tenant context, so Cache-facade reads would
 * store tagged entries the central untagged forget can never reach. Stancl's
 * GlobalCache is never swapped — one tenancy-neutral keyspace, isolation by
 * the vertical embedded in the key. Same mechanism as CompanyConfigService;
 * regression: tests/Feature/Services/TenantConfigCacheTenancyTest.php.
 */
class VerticalConfigService
{
    private const CACHE_TTL_SECONDS = 86400; // 24 hours

    private const CACHE_KEY_PREFIX = 'vertical_config_override:';

    /**
     * Get complete configuration for a vertical
     *
     * @return array<string, mixed>
     */
    public function getVerticalConfig(Vertical $vertical): array
    {
        $config = config("verticals.{$vertical->value}");

        if (! is_array($config)) {
            throw new \RuntimeException("Configuration not found for vertical: {$vertical->value}");
        }

        $override = $this->getOverride($vertical);

        if ($override['default_modules'] !== null) {
            $config['default_modules'] = $override['default_modules'];
        }

        if ($override['compatible_extras'] !== null) {
            $config['compatible_extras'] = $override['compatible_extras'];
        }

        return $config;
    }

    /**
     * Forget the cached DB override for a vertical so the next read
     * re-queries the central vertical_configs table.
     */
    public function invalidateVertical(Vertical $vertical): void
    {
        GlobalCache::forget(self::CACHE_KEY_PREFIX.$vertical->value);
    }

    /**
     * Get the cached central-DB override row for a vertical.
     *
     * The result is always a sentinel array (never null) so that a missing
     * row is cached too — Cache::remember treats null as a miss and would
     * otherwise re-query on every call.
     *
     * @return array{default_modules: array<int, string>|null, compatible_extras: array<int, string>|null}
     */
    private function getOverride(Vertical $vertical): array
    {
        /** @var array{default_modules: array<int, string>|null, compatible_extras: array<int, string>|null} $override */
        $override = GlobalCache::remember(
            self::CACHE_KEY_PREFIX.$vertical->value,
            self::CACHE_TTL_SECONDS,
            function () use ($vertical): array {
                $model = new VerticalConfig;

                // Fail open to config-file values when the central table does
                // not exist yet (pre-migration deploys, bare test harnesses).
                if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
                    return ['default_modules' => null, 'compatible_extras' => null];
                }

                $row = VerticalConfig::query()->find($vertical->value);

                return [
                    'default_modules' => $row?->default_modules,
                    'compatible_extras' => $row?->compatible_extras,
                ];
            }
        );

        return $override;
    }

    /**
     * Get the human-readable label for a vertical
     */
    public function getLabel(Vertical $vertical): string
    {
        $config = $this->getVerticalConfig($vertical);

        return (string) ($config['label'] ?? $vertical->value);
    }

    /**
     * Get the description for a vertical
     */
    public function getDescription(Vertical $vertical): string
    {
        $config = $this->getVerticalConfig($vertical);

        return (string) ($config['description'] ?? '');
    }

    /**
     * Get the product (izipos or otospex) for a vertical
     */
    public function getProduct(Vertical $vertical): string
    {
        $config = $this->getVerticalConfig($vertical);

        return (string) ($config['product'] ?? 'izipos');
    }

    /**
     * Get compatible extras (optional modules) for a vertical
     *
     * @return array<int, string>
     */
    public function getCompatibleExtras(Vertical $vertical): array
    {
        $config = $this->getVerticalConfig($vertical);

        return (array) ($config['compatible_extras'] ?? []);
    }

    /**
     * Get default modules for a vertical
     *
     * @return array<int, string>
     */
    public function getDefaultModules(Vertical $vertical): array
    {
        $config = $this->getVerticalConfig($vertical);

        return (array) ($config['default_modules'] ?? []);
    }

    /**
     * Get default product attributes for a vertical.
     *
     * @return array{requires_batch_tracking: bool}
     */
    public function getProductDefaults(Vertical $vertical): array
    {
        $config = $this->getVerticalConfig($vertical);
        $defaults = (array) ($config['product_defaults'] ?? []);

        return [
            'requires_batch_tracking' => (bool) ($defaults['requires_batch_tracking'] ?? false),
        ];
    }

    /**
     * Get all verticals for a specific product
     *
     * @return array<int, Vertical>
     */
    public function getVerticalsForProduct(string $product): array
    {
        return array_filter(
            Vertical::cases(),
            fn (Vertical $vertical): bool => $this->getProduct($vertical) === $product
        );
    }
}
