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
        /*
        |----------------------------------------------------------------------
        | Marketplace kill-switch — `config('marketplace.enabled')`
        |----------------------------------------------------------------------
        |
        | The flag shipped with the module in config/marketplace.php but had
        | ZERO readers, so the entire Marketplace surface was live in every
        | tenant. It gates two things here (and the `marketplace-checkout`
        | endpoint in App\Modules\Cart\Presentation\routes.php):
        |
        |   1. Route registration. `marketplace.browse` is seeded to EVERY role
        |      by RolesAndPermissionsSeeder, and the "admin" seller-management
        |      group — documented on MarketplaceSellerController as SUPER-ADMIN,
        |      fleet-wide semantics — runs under the ordinary tenant stack
        |      behind `can:marketplace.admin`, a permission the seeder grants to
        |      every tenant admin. Until that surface is redesigned (ticket:
        |      docs/superpowers/tickets/2026-08-05-marketplace-admin-surface-redesign.md)
        |      the flag, default FALSE, is what keeps it unreachable.
        |
        |   2. Scheduling. `marketplace:delta-sync` / `marketplace:reconcile`
        |      guard on `MarketplaceSeller::active()->exists()`, which is
        |      data-presence, not capability — a tenant that happens to hold a
        |      seller row would be swept into a fan-out for a module it never
        |      enabled.
        |
        | Console command REGISTRATION stays unconditional: the flag gates the
        | automatic fan-out and the HTTP surface, not operator-driven
        | maintenance, so an operator can still run a reconciliation by hand
        | while the module is dark.
        |
        | No `module:Marketplace` middleware gate is added, mirroring the same
        | note in App\Modules\Procurement\Presentation\routes.php:20-23:
        | 'Marketplace' is not a case of the ModuleName enum, and ModuleNameTest
        | pins that enum to the union of config/verticals.php — so adding it is
        | a product decision about which verticals sell the module, deferred to
        | the vertical-module-gating audit
        | (docs/superpowers/audits/2026-06-15-vertical-module-gating-audit/).
        | Once inside the flag, access remains governed by per-route `can:`.
        |
        | NOTE: the route-registration half of this condition is deliberately
        | DUPLICATED at the top of ../Presentation/routes.php, and that copy is
        | the load-bearing one — spatie/laravel-event-sourcing's projector
        | auto-discovery `include`s every file under app() as a side effect of
        | its PSR-4 `is_subclass_of()` probe, so the routes file registers itself
        | even when this provider never calls loadRoutesFrom(). See the comment
        | block in that file for the verified backtrace.
        */
        $marketplaceEnabled = (bool) config('marketplace.enabled', false);

        if ($marketplaceEnabled) {
            $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                MarketplaceDeltaSyncCommand::class,
                MarketplaceReconcileCommand::class,
            ]);
        }

        if (! $marketplaceEnabled) {
            return;
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
        // In-process (no runInBackground()). onFailure() is NOT lost by
        // backgrounding — a background event's forked process re-invokes
        // `schedule:finish`, which calls Event::finish() ->
        // callAfterCallbacks() gated on the child's exit code. Foreground is
        // chosen because the exit code is observed inline in the scheduler
        // process, so failure signalling does not depend on the forked child
        // surviving long enough to re-invoke `schedule:finish` (a killed child
        // leaves the after-callbacks uncalled and its overlap mutex to expire
        // on its own timer). Trade-off, accepted: delta-sync runs on a
        // 15-minute cadence, so a slow fan-out serialises that scheduler tick.
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
