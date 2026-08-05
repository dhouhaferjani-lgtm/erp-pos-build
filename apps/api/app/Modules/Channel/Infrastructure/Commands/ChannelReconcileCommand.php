<?php

declare(strict_types=1);

namespace App\Modules\Channel\Infrastructure\Commands;

use App\Console\Concerns\WarnsOnTenantScopeDrift;
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
 * **It also owns both directions of the central webhook directory** (2026-08-05
 * review, R2). Registration self-heals channels that predate the directory;
 * the PRUNE removes pointers whose channel is gone from the owning tenant
 * (deleted outside Eloquent, so the `deleted`-event `forget()` never fired) and
 * pointers whose tenant has left the central directory entirely. Without the
 * prune each stale row costs an anonymous caller a full tenant database switch
 * before its 404 — `ChannelWebhookController` binds tenancy before it can
 * discover the channel row is missing — which partly undoes this design's own
 * reason for rejecting a per-tenant fan-out.
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
    use WarnsOnTenantScopeDrift;

    /** Cap on drifted ids collected for the R1 warning. */
    private const MAX_REPORTED_DRIFT_IDS = 20;

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
        $pruned = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (&$dispatched, &$pruned): int {
            $ownedByTenant = static function (Builder $query) use ($tenant): void {
                /** @var Builder<Company> $query */
                $query->where('tenant_id', $tenant->id);
            };

            $channels = Channel::query()->whereHas('company', $ownedByTenant)->get();

            // R1: under database-per-tenant the predicate above should be a
            // no-op — every channel in THIS database belongs to THIS tenant.
            // When it is not, the excluded channel is never reconciled AND
            // never gets a directory pointer, so its webhooks 404 forever. The
            // silence is the bug; say so.
            $this->warnOnTenantScopeDrift(
                'channels',
                $tenant,
                static fn (): int => Channel::query()->count(),
                static fn (): int => $channels->count(),
                static fn (): array => array_values(
                    Channel::query()
                        ->whereDoesntHave('company', $ownedByTenant)
                        ->limit(self::MAX_REPORTED_DRIFT_IDS)
                        ->pluck('id')
                        ->map(static fn (mixed $id): string => (string) $id)
                        ->all(),
                ),
            );

            foreach ($channels as $channel) {
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
                    continue;
                }

                // The ITERATING tenant, not a re-read of company.tenant_id:
                // what the worker must rebind is the database the channel
                // row was actually read from.
                ChannelReconciliationJob::dispatch($channel->id, $tenant->id);
                $dispatched++;
            }

            // PRUNE, after the self-heal above so a pointer this very run
            // backfilled is never mistaken for a stale one. Only reached when
            // the enumeration ABOVE completed: a throw mid-enumeration is
            // caught by forEachTenant() and this tenant's pointers are left
            // alone rather than deleted against a partial channel list.
            /** @var list<string> $liveChannelIds */
            $liveChannelIds = array_values(
                $channels->map(static fn (Channel $channel): string => (string) $channel->id)->all(),
            );

            $pruned += count($this->directory->pruneTenant((string) $tenant->id, $liveChannelIds));

            return self::SUCCESS;
        });

        // The deprovisioning leg: no per-tenant slot ever opens for a tenant
        // that is gone from the central directory, so its pointers can only be
        // reached from outside the iteration. A tenant that is merely
        // UNREACHABLE (missing/failed database probe) still has its directory
        // row, so this never prunes it — only a tenant row that no longer
        // exists at all.
        $pruned += $this->directory->pruneDeprovisionedTenants();

        $this->info("Dispatched {$dispatched} channel reconciliation job(s).");
        $this->info("Pruned {$pruned} stale webhook directory pointer(s).");

        return $exit;
    }
}
