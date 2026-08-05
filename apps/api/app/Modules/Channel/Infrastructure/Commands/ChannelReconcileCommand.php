<?php

declare(strict_types=1);

namespace App\Modules\Channel\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Channel\Application\Jobs\ChannelReconciliationJob;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Infrastructure\Directory\ChannelWebhookDirectoryRegistrar;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fan out a reconciliation job per active sales channel, per tenant.
 *
 * Scheduled nightly at 03:30 (see ChannelServiceProvider). Replaces the
 * `$schedule->call(closure)` registration that ran `Channel::query()` directly
 * inside the scheduler's CENTRAL container.
 *
 * **Why that closure was the worst shape in the 2026-08-05 cat-(b) re-sweep.**
 * It opened with `if (! Schema::hasTable('channels')) { return; }`. Under
 * database-per-tenant `channels` is a TENANT table that does not exist on the
 * central connection the scheduler runs on, so the guard was permanently true:
 * every nightly tick returned cleanly, the scheduler recorded a SUCCESS, and
 * there was no exception, no log line, no `failed_jobs` row and no non-zero
 * exit. Unlike the other broken surfaces this one produced no signal at all.
 * The guard is deliberately NOT carried across — inside `forEachTenant()` a
 * missing `channels` table means a MIS-MIGRATED tenant, which must surface as
 * that tenant's failure, not as "nothing to do".
 *
 * Tenant-isolation: cat-(a-per-tenant-iter). Per master plan §14 invariant 2,
 * schedulers MUST iterate explicitly per tenant; they MUST NOT issue
 * cross-tenant queries from the command body. The channel query AND the
 * dispatch both run inside the tenant's context, so QueueTenancyBootstrapper
 * stamps the tenant onto each payload; the iterating tenant's id is ALSO passed
 * explicitly so {@see ChannelReconciliationJob} (which uses BindsTenantContext)
 * still binds the right database when the bootstrapper's stamp is absent
 * (queue:retry, manual re-queue, synchronous console dispatch).
 *
 * **Legacy row-level mode** (`tenancy_resolver.db_per_tenant=false` — the test
 * suite and pre-flip compat): `forEachTenant()` runs the closure once per
 * tenant WITHOUT switching databases, so an unfiltered `Channel::query()` would
 * fan every tenant's channels out N times. `channels` has no `tenant_id` column
 * of its own, so the predicate goes through its company — the same explicit
 * guard `d6ed4e481` added to the batch queries. It is redundant (but harmless)
 * under database-per-tenant, where the tenant database contains only that
 * tenant's companies.
 */
final class ChannelReconcileCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'channels:reconcile';

    /** @var string */
    protected $description = 'Dispatch a reconciliation job for every active sales channel, per tenant';

    public function __construct(
        CompanyContext $companyContext,
        private readonly ChannelWebhookDirectoryRegistrar $directory,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $dispatched = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (&$dispatched): int {
            Channel::query()
                ->whereHas('company', static function (Builder $query) use ($tenant): void {
                    /** @var Builder<Company> $query */
                    $query->where('tenant_id', $tenant->id);
                })
                ->each(function (Channel $channel) use ($tenant, &$dispatched): void {
                    // Self-heal the CENTRAL webhook directory. Channels created
                    // before that table existed have no pointer, and a central
                    // migration cannot backfill across tenant databases — this
                    // nightly per-tenant sweep is the only place that already
                    // visits every channel of every tenant. Runs for INACTIVE
                    // channels too: an external platform can still call a
                    // deactivated channel's webhook, and the honest answer is a
                    // resolvable tenant, not a 404 that looks like data loss.
                    $this->directory->register($channel);

                    if (! $channel->is_active) {
                        return;
                    }

                    // The ITERATING tenant, not a re-read of company.tenant_id:
                    // what the worker must rebind is the database the channel
                    // row was actually read from.
                    ChannelReconciliationJob::dispatch($channel->id, $tenant->id);
                    $dispatched++;
                });

            return self::SUCCESS;
        });

        $this->info("Dispatched {$dispatched} channel reconciliation job(s).");

        return $exit;
    }
}
