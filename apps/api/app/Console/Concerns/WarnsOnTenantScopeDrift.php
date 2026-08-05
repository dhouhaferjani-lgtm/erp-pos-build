<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Console\TenantScopedCommand;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Make the compat-mode tenant predicate's side effect VISIBLE under
 * database-per-tenant.
 *
 * R1 (2026-08-05 cat-(b) wave-1 adversarial review). Every wave-1 conversion
 * narrows its per-tenant query with an explicit `tenant_id` predicate (directly
 * on `companies`, or through the company for `channels`). That predicate is
 * mandatory under the pre-flip compat mode
 * (`tenancy_resolver.db_per_tenant=false`), where all tenants share one
 * database and its absence would make each tenant's pass sweep the fleet.
 *
 * Under database-per-tenant it is redundant *only while the column is correct*.
 * `companies.tenant_id` also exists inside the tenant database, and it can
 * drift — a tenant DB restored from another tenant's dump, a seeder default, a
 * mis-provisioned tenant. A drifted row is then SILENTLY excluded where the
 * pre-conversion fleet-wide query would have picked it up. For
 * `channels:reconcile` the consequence compounds: the channel is never
 * reconciled AND never receives a webhook-directory pointer, so its webhooks
 * fail closed with a 404 forever and nothing anywhere says why.
 *
 * Deliberately inert under compat mode: there the unfiltered set legitimately
 * contains every other tenant's rows, so a delta carries no information and the
 * counting queries are not even issued.
 *
 * @see TenantScopedCommand::forEachTenant()
 */
trait WarnsOnTenantScopeDrift
{
    /** Cap on ids echoed into the log line — drift can be fleet-sized. */
    private const MAX_LOGGED_DRIFT_IDS = 20;

    /**
     * Compare the tenant database's unfiltered row count against the count the
     * `tenant_id` predicate matched, and WARN on the delta.
     *
     * The probes are closures, not values, so nothing is queried in the mode
     * where the answer would be meaningless — and the (more expensive) id probe
     * only runs once a delta is known to exist.
     *
     * @param  string  $resource  the table/aggregate being scoped, for the log line
     * @param  callable(): int  $countAll  rows visible in this tenant's database, unfiltered
     * @param  callable(): int  $countMatched  rows the `tenant_id` predicate matched
     * @param  callable(): list<string>  $droppedIds  ids the predicate excluded (caller may cap)
     * @return int the number of rows the predicate dropped (0 when inert or clean)
     */
    protected function warnOnTenantScopeDrift(
        string $resource,
        Tenant $tenant,
        callable $countAll,
        callable $countMatched,
        callable $droppedIds,
    ): int {
        if (! (bool) config('tenancy_resolver.db_per_tenant', false)) {
            return 0;
        }

        $all = $countAll();
        $matched = $countMatched();

        if ($all <= $matched) {
            return 0;
        }

        $dropped = $all - $matched;

        Log::warning('Tenant scope drift: rows inside the tenant database were EXCLUDED by the tenant_id predicate. Their tenant_id does not match the tenant that owns this database, so they are invisible to this command — and, for channels, permanently unroutable. Investigate the tenant_id column on the listed rows; do not remove the predicate, which is what keeps compat mode correct.', [
            'resource' => $resource,
            'tenant_id' => $tenant->id,
            'command' => static::class,
            'rows_in_tenant_database' => $all,
            'rows_matching_predicate' => $matched,
            'rows_dropped' => $dropped,
            'dropped_ids' => array_slice($droppedIds(), 0, self::MAX_LOGGED_DRIFT_IDS),
        ]);

        return $dropped;
    }
}
