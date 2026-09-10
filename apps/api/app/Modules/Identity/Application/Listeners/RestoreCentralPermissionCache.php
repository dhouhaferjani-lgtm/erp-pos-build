<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Listeners;

use App\Modules\Identity\Application\Support\PermissionCacheKey;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenancyEnded;

final class RestoreCentralPermissionCache
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly PermissionCacheKey $cacheKey,
    ) {}

    public function handle(TenancyEnded $event): void
    {
        if (! (bool) config('tenancy_resolver.db_per_tenant', false)) {
            return;
        }
        config(['permission.cache.key' => $this->cacheKey->base()]);
        $this->registrar->initializeCache();
        $this->registrar->clearPermissionsCollection();
    }
}
