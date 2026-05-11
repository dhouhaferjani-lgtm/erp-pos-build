<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Application\Services\CompositeItemImportService;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\POS\Infrastructure\Broadcasting\CatalogModelObserver;
use App\Shared\Contracts\CompositeItemServiceInterface;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CompositeItemServiceInterface::class, CompositeItemImportService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        // Bug 1 — composite items surface in the POS active-menu payload
        // for Menu tenants. CompositeItem carries tenant_id / company_id
        // directly, so the observer's default attribute-lookup path is
        // sufficient. Codex r2 P2 closure — added because CompositeItem
        // mutations were missing from the v1 ingress list.
        CompositeItem::observe(CatalogModelObserver::class);
    }
}
