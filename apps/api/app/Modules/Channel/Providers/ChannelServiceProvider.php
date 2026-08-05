<?php

declare(strict_types=1);

namespace App\Modules\Channel\Providers;

use App\Modules\Channel\Application\Listeners\DispatchStockChangeToChannels;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Infrastructure\Commands\ChannelReconcileCommand;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class ChannelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        Event::listen(StockMovementRecorded::class, DispatchStockChangeToChannels::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ChannelReconcileCommand::class,
            ]);
        }

        // Nightly channel reconciliation fan-out.
        //
        // Until 2026-08-05 this was a `$schedule->call(closure)` registration
        // that ran `Channel::query()` inside the scheduler's CENTRAL container,
        // behind `if (! Schema::hasTable('channels')) return;`. `channels` is a
        // TENANT table, so under database-per-tenant that guard was permanently
        // true: the closure returned cleanly and the scheduler recorded a
        // SUCCESS every single night. No exception, no log line, no failed_jobs
        // row — the only surface in the cat-(b) re-sweep that produced no signal
        // whatsoever. It now runs as a TenantScopedCommand that iterates tenants
        // explicitly, at the same 03:30 cadence.
        //
        // In-process (no runInBackground()) for the reason recorded on the
        // marketplace entries: onFailure() fires in both modes, but foreground
        // observes the exit code inline instead of depending on a forked child
        // surviving long enough to re-invoke `schedule:finish`. This runs once a
        // night and only enumerates channels + pushes jobs (the adapter I/O all
        // happens inside ChannelReconciliationJob on a worker), so serialising
        // the tick costs nothing.
        //
        // withoutOverlapping(30) caps the mutex at 30 minutes rather than the
        // bare 1440-minute default. For a dailyAt() entry that default is
        // exactly the gap to the next run, so one crashed process can swallow
        // the following night's reconciliation entirely.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('channels:reconcile')
                ->dailyAt('03:30')
                ->withoutOverlapping(30)
                ->onFailure(function (): void {
                    Log::error('channels:reconcile exited non-zero — one or more tenants failed to fan out their nightly sales-channel reconciliation, so drift between locally published product mappings and the remote channel (ChannelSyncDriftDetected) goes undetected for those tenants until a later run succeeds. Per-tenant detail is in the application error log under the TenantScopedCommand::forEachTenant failure entry (tenant_id + exception).');
                });
        });
    }
}
