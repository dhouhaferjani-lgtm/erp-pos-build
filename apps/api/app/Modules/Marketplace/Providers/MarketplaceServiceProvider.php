<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Providers;

use App\Modules\Marketplace\Infrastructure\Commands\MarketplaceDeltaSyncCommand;
use App\Modules\Marketplace\Infrastructure\Commands\MarketplaceReconcileCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                MarketplaceDeltaSyncCommand::class,
                MarketplaceReconcileCommand::class,
            ]);
        }

        // Schedule delta sync and reconciliation.
        //
        // Until 2026-08-04 these were two `$schedule->call(closure)`
        // registrations that ran `MarketplaceSeller::active()` inside the
        // scheduler's CENTRAL container. Under database-per-tenant that query
        // throws before any job is dispatched, and because it fails in the
        // scheduler process (not a worker) it never reaches `failed_jobs` — the
        // fan-out was silently dead. Both now run as TenantScopedCommands that
        // iterate tenants explicitly, at the SAME config-driven cadence.
        //
        // In-process (no runInBackground()) so the scheduler observes the exit
        // code and the onFailure hooks below actually fire.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $intervalMinutes = (int) config('marketplace.sync.delta_interval_minutes', 15);
            $reconcileHour = (int) config('marketplace.sync.reconciliation_hour', 3);

            $schedule->command('marketplace:delta-sync')
                ->cron("*/{$intervalMinutes} * * * *")
                ->withoutOverlapping(30)
                ->onFailure(function (): void {
                    Log::error('marketplace:delta-sync exited non-zero — one or more tenants failed to fan out their per-seller listing delta sync, so those sellers\' marketplace listings are stale until a later tick succeeds. Per-tenant detail is in the application error log under the TenantScopedCommand::forEachTenant failure entry (tenant_id + exception).');
                });

            $schedule->command('marketplace:reconcile')
                ->dailyAt(sprintf('%02d:00', $reconcileHour))
                ->withoutOverlapping()
                ->onFailure(function (): void {
                    Log::error('marketplace:reconcile exited non-zero — one or more tenants failed to fan out their nightly full listing reconciliation, so listings whose source products were deactivated or deleted may remain live on the marketplace. Per-tenant detail is in the application error log under the TenantScopedCommand::forEachTenant failure entry (tenant_id + exception).');
                });
        });
    }
}
