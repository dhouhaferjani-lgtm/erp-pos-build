<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Commands;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Database\TenantCollection;
use Throwable;

/**
 * @cross-tenant-by-design Rolls pending tenant-scoped migrations out across EVERY provisioned per-tenant database. It must iterate all tenants and initialize each in turn, so it deliberately operates without a single bound CompanyContext — the same cat-(a-per-tenant-iter) shape as the Stancl-driven migration path it wraps.
 *
 * T6 — rolling tenant-migration runner.
 *
 * Stancl ships `tenants:migrate`, which migrates the tenant databases. But its
 * underlying `tenancy()->runForMultiple()` loop is **fail-fast**: an exception
 * while migrating one tenant aborts the whole pass, leaving the remaining
 * tenants un-migrated and tenancy possibly still initialized. For a fleet-wide
 * rollout we want the opposite: a failure on ONE tenant must be isolated and
 * reported, never silently aborting the rest.
 *
 * This command therefore drives the built-in `tenants:migrate` **one tenant at
 * a time** (so a single tenant's failure is contained to its own sub-call),
 * wraps each in try/catch, and aggregates the outcome. It honours the
 * `tenancy.migration_parameters` config (path = `database/migrations/tenant`),
 * so it reuses the exact migrator + path the at-creation `MigrateDatabase` job
 * uses — no parallel migration mechanism.
 *
 * Failure policy: **continue-and-collect-errors.** Every tenant is attempted;
 * failures are collected and surfaced in a final summary, and the command exits
 * non-zero if any tenant failed (so CI / schedulers notice) while still having
 * migrated all the healthy ones.
 *
 * Idempotency: re-running is safe. Stancl's migrator records applied migrations
 * in each tenant DB's `migrations` table, so an already-up-to-date tenant is a
 * no-op ("Nothing to migrate").
 *
 * Compat mode: when `tenancy_resolver.db_per_tenant` is false (the pre-flip,
 * single shared-database reality), there are no per-tenant databases to migrate
 * — tenant migrations already load into the shared schema. The command is a
 * documented no-op in that mode and returns SUCCESS without touching anything.
 */
class RollingTenantMigrationCommand extends Command
{
    protected $signature = 'tenants:migrate-rolling
                            {--tenant= : Restrict the rollout to a single tenant UUID (default: every tenant)}
                            {--force : Run migrations without the interactive confirmation prompt}
                            {--pretend : Dump the SQL queries that would be run without executing them}';

    protected $description = 'Roll pending tenant-scoped migrations out across all provisioned per-tenant databases, isolating per-tenant failures.';

    public function handle(): int
    {
        if (! (bool) config('tenancy_resolver.db_per_tenant', false)) {
            $this->info('Database-per-tenant mode is OFF (tenancy_resolver.db_per_tenant=false).');
            $this->line('Tenant migrations already load into the shared database; nothing to roll out. No-op.');

            return self::SUCCESS;
        }

        $tenants = $this->resolveTenants();

        $tenantCount = $tenants->count();
        if ($tenantCount === 0) {
            $this->info('No tenants to migrate.');

            return self::SUCCESS;
        }

        $this->info("Rolling tenant migrations across {$tenantCount} tenant(s).");
        $this->newLine();

        $succeeded = 0;
        /** @var list<array{tenant: string, error: string}> $failures */
        $failures = [];

        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            $key = (string) $tenant->getTenantKey();
            $label = $tenant->slug !== '' ? "{$tenant->slug} ({$key})" : $key;

            $this->line("→ {$label}");

            try {
                $this->migrateSingleTenant($key);
                $succeeded++;
            } catch (Throwable $e) {
                // Isolate: never let one tenant's failure abort the fleet.
                $failures[] = ['tenant' => $key, 'error' => $e->getMessage()];
                $this->error("  FAILED: {$e->getMessage()}");
            } finally {
                // The per-tenant sub-call initializes tenancy; make sure we always
                // revert before the next tenant so a leaked context can't bleed.
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        return $this->report($succeeded, $failures);
    }

    /**
     * Run the built-in Stancl migrator for exactly one tenant. Output from the
     * sub-command is echoed verbatim so per-tenant progress is visible.
     */
    private function migrateSingleTenant(string $tenantKey): void
    {
        $parameters = [
            '--tenants' => [$tenantKey],
            '--force' => (bool) $this->option('force'),
        ];

        if ((bool) $this->option('pretend')) {
            $parameters['--pretend'] = true;
        }

        $exitCode = Artisan::call('tenants:migrate', $parameters);

        $output = trim(Artisan::output());
        if ($output !== '') {
            foreach (explode("\n", $output) as $outputLine) {
                $this->line('  '.$outputLine);
            }
        }

        if ($exitCode !== self::SUCCESS) {
            throw new \RuntimeException("tenants:migrate exited with code {$exitCode}.");
        }
    }

    /**
     * The tenants this run targets: all of them, or the single `--tenant` UUID.
     */
    private function resolveTenants(): TenantCollection
    {
        $only = $this->option('tenant');

        if (is_string($only) && $only !== '') {
            $filtered = Tenant::query()->where('id', $only)->get();

            if ($filtered->count() === 0) {
                $this->warn("No tenant found with id '{$only}'.");
            }

            return $filtered;
        }

        return Tenant::all();
    }

    /**
     * @param  list<array{tenant: string, error: string}>  $failures
     */
    private function report(int $succeeded, array $failures): int
    {
        $this->newLine();

        if ($failures === []) {
            $this->info("Done. {$succeeded} tenant(s) migrated, 0 failed.");

            return self::SUCCESS;
        }

        $failedCount = count($failures);
        $this->error("Done with errors. {$succeeded} tenant(s) migrated, {$failedCount} failed:");
        foreach ($failures as $failure) {
            $this->error("  - {$failure['tenant']}: {$failure['error']}");
        }

        return self::FAILURE;
    }
}
