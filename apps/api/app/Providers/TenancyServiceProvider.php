<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

/**
 * T6 Phase 0b — Stancl tenancy bootstrap wiring, gated on database-per-tenant mode.
 *
 * The app historically ran row-level multi-tenancy in a single shared schema, so
 * `tenancy()->initialize()` set `initialized = true` but never actually swapped
 * the database connection — the standard Stancl event wiring
 * (TenancyInitialized -> BootstrapTenancy) was never registered. That no-op was
 * invisible while every tenant shared one database, and a lot of code paths rely
 * on it: jobs using the BindsTenantContext trait call `$tenant->run()` which
 * initializes tenancy and, pre-flip, simply continued on the shared connection.
 *
 * After the database-per-tenant flip, initializing tenancy MUST run the
 * configured bootstrappers (DatabaseTenancyBootstrapper swaps the default
 * connection to the tenant database, and reverts it on end). But that swap is
 * only correct when real per-tenant databases exist — i.e. in DB-per-tenant
 * mode. So the bootstrap is gated on `tenancy_resolver.db_per_tenant`, checked
 * at FIRE time (not boot time, so tests can opt in via
 * `config(['tenancy_resolver.db_per_tenant' => true])`):
 *
 *   - mode OFF (the single-connection compat suite, and pre-flip prod): the
 *     listeners are a no-op, so `tenancy()->initialize()` / `$tenant->run()`
 *     keep running on the shared connection exactly as before — no attempt to
 *     open a non-existent per-tenant database.
 *   - mode ON (post-flip prod, and the PG-only flip/PAT tests): the bootstrap
 *     runs and the connection swaps to the tenant database.
 *
 * Only the bootstrap/revert listeners are wired — NOT TenantCreated ->
 * CreateDatabase/MigrateDatabase, which would attempt `CREATE DATABASE` for
 * every Tenant row (e.g. in tests, inside a RefreshDatabase transaction, which
 * PostgreSQL forbids). Tenant database provisioning is driven explicitly.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(TenancyInitialized::class, function (TenancyInitialized $event): void {
            if ((bool) config('tenancy_resolver.db_per_tenant', false)) {
                app(BootstrapTenancy::class)->handle($event);
            }
        });

        Event::listen(TenancyEnded::class, function (TenancyEnded $event): void {
            if ((bool) config('tenancy_resolver.db_per_tenant', false)) {
                app(RevertToCentralContext::class)->handle($event);
            }
        });
    }
}
