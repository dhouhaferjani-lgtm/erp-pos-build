<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Providers;

use App\Modules\Taxation\Domain\Services\StampDutyService;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Taxation\Domain\Services\TaxResolutionService;
use Illuminate\Support\ServiceProvider;

class TaxationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register taxation services as singletons
        $this->app->singleton(StampDutyService::class);
        $this->app->singleton(TaxResolutionService::class);
        $this->app->singleton(TaxCalculationService::class);
    }

    public function boot(): void
    {
        // Load taxation module routes
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
