<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Listeners;

use LogicException;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\TenancyInitialized;

final class ScopePermissionCacheToTenant
{
    public const BASE_KEY = 'spatie.permission.cache';

    public function __construct(private readonly PermissionRegistrar $registrar) {}

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
        config(['permission.cache.key' => self::BASE_KEY.'.'.$tenantId]);
        $this->registrar->initializeCache();
        $this->registrar->clearPermissionsCollection();
    }
}
