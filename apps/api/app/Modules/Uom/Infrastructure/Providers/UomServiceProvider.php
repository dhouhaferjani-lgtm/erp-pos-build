<?php

declare(strict_types=1);

namespace App\Modules\Uom\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;

class UomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/routes.php');
    }
}
