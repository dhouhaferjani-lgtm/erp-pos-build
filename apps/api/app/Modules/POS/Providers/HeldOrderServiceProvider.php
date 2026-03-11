<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use App\Modules\POS\Infrastructure\Commands\ExpireHeldOrdersCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for POS Held Orders (cart parking) feature.
 *
 * Registers routes, commands, and scheduling for held order management.
 */
final class HeldOrderServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Services are auto-resolved via constructor injection
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes_held_orders.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireHeldOrdersCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('pos:expire-held-orders')->everyFifteenMinutes();
        });
    }
}
