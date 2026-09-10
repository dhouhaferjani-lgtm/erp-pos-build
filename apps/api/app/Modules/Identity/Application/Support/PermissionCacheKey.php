<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use App\Providers\TenancyServiceProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Stancl\Tenancy\Events\TenancyInitialized;

/**
 * The Spatie permission cache key as CONFIGURED, captured once at boot.
 *
 * The tenant listeners rewrite `permission.cache.key` on every tenancy
 * transition, so `config('permission.cache.key')` is only trustworthy BEFORE
 * the first {@see TenancyInitialized}. Reading it back
 * out of the config repository to "restore central" would therefore restore
 * whatever the last transition wrote, and hardcoding the package default
 * (`spatie.permission.cache`) silently discards a deployment that customised
 * `permission.cache.key` in config/permission.php.
 *
 * This object is bound as a container SINGLETON and resolved eagerly in
 * {@see TenancyServiceProvider::boot()}, so the value it holds
 * is the one the application booted with — the base key in central context,
 * and the stem every per-tenant key is derived from.
 */
final class PermissionCacheKey
{
    /**
     * Spatie's own default, used only when `permission.cache.key` is absent or
     * not a non-empty string. Identical to config/permission.php's shipped value,
     * so behaviour is unchanged for any deployment on the default.
     */
    public const DEFAULT_BASE_KEY = 'spatie.permission.cache';

    private readonly string $baseKey;

    public function __construct(ConfigRepository $config)
    {
        $configured = $config->get('permission.cache.key');

        $this->baseKey = is_string($configured) && $configured !== ''
            ? $configured
            : self::DEFAULT_BASE_KEY;
    }

    /**
     * The key used in CENTRAL context and in compatibility (single-schema) mode.
     */
    public function base(): string
    {
        return $this->baseKey;
    }

    /**
     * The key used while tenancy is initialized for `$tenantId`.
     */
    public function forTenant(string $tenantId): string
    {
        return $this->baseKey.'.'.$tenantId;
    }
}
