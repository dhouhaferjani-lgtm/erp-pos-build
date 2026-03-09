<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Providers;

use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Marketplace\Infrastructure\Jobs\ReconcileListingsJob;
use App\Modules\Marketplace\Infrastructure\Jobs\SyncSellerListingsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../../../config/marketplace.php',
            'marketplace',
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        // Schedule delta sync and reconciliation
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $intervalMinutes = (int) config('marketplace.sync.delta_interval_minutes', 15);
            $reconcileHour = (int) config('marketplace.sync.reconciliation_hour', 3);

            $schedule->call(function (): void {
                MarketplaceSeller::active()->each(function (MarketplaceSeller $seller): void {
                    SyncSellerListingsJob::dispatch($seller->id);
                });
            })->cron("*/{$intervalMinutes} * * * *")->name('marketplace:delta-sync');

            $schedule->call(function (): void {
                MarketplaceSeller::active()->each(function (MarketplaceSeller $seller): void {
                    ReconcileListingsJob::dispatch($seller->id);
                });
            })->dailyAt(sprintf('%02d:00', $reconcileHour))->name('marketplace:reconcile');
        });
    }
}
