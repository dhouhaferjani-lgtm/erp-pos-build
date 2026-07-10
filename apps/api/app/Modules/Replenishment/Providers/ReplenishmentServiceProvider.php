<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Providers;

use Illuminate\Support\ServiceProvider;

final class ReplenishmentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
