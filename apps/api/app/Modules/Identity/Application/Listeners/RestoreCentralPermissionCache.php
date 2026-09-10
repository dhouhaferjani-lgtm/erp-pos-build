<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Listeners;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenancyEnded;

final class RestoreCentralPermissionCache
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function handle(TenancyEnded $event): void
    {
        if (! (bool) config('tenancy_resolver.db_per_tenant', false)) {
            return;
        }
        config(['permission.cache.key' => ScopePermissionCacheToTenant::BASE_KEY]);
        $this->registrar->initializeCache();
        $this->registrar->clearPermissionsCollection();
    }
}
