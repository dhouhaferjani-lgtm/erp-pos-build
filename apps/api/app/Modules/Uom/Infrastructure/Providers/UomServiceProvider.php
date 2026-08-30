<?php

declare(strict_types=1);

namespace App\Modules\Uom\Infrastructure\Providers;

use App\Modules\Uom\Application\Services\UnitCatalogQuery;
use App\Shared\Contracts\UnitCatalogQueryInterface;
use Illuminate\Support\ServiceProvider;

class UomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UnitCatalogQueryInterface::class, UnitCatalogQuery::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/routes.php');
    }
}
