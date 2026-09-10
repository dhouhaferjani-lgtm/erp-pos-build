<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Listeners;

use App\Modules\Identity\Application\Support\PermissionCacheKey;
use LogicException;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\TenancyInitialized;

final class ScopePermissionCacheToTenant
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly PermissionCacheKey $cacheKey,
    ) {}

    public function handle(TenancyInitialized $event): void
    {
        if (! (bool) config('tenancy_resolver.db_per_tenant', false)) {
            return;
        }
        $tenant = $event->tenancy->tenant;
        if (! $tenant instanceof Tenant) {
            throw new LogicException('Permission cache scoping requires an initialized tenant.');
        }

        $tenantId = (string) $tenant->getTenantKey();
        config(['permission.cache.key' => $this->cacheKey->forTenant($tenantId)]);
        $this->registrar->initializeCache();
        $this->registrar->clearPermissionsCollection();
    }
}
