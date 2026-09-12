<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry;

use App\Console\Commands\ApplyLotActionPermissionDelta;
use App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface;
use App\Modules\BatchExpiry\Infrastructure\Commands\BatchExpiryDailyCheckCommand;
use App\Modules\BatchExpiry\Infrastructure\Persistence\BatchRepository;
use Illuminate\Support\ServiceProvider;

class BatchExpiryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind repository interface to implementation
        $this->app->bind(
            BatchRepositoryInterface::class,
            BatchRepository::class
        );
    }

    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/Presentation/routes.php');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BatchExpiryDailyCheckCommand::class,
                ApplyLotActionPermissionDelta::class,
            ]);
        }
    }
}
