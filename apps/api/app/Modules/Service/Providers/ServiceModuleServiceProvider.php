<?php

declare(strict_types=1);

namespace App\Modules\Service\Providers;

use Illuminate\Support\ServiceProvider;

class ServiceModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
