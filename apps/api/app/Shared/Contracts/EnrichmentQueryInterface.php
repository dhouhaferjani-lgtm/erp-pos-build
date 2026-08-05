<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\PendingEnrichmentDTO;
use Illuminate\Support\Collection;

/**
 * Interface for querying products with pending enrichment submissions.
 *
 * Implemented by Product module, consumed by PlatformIntegration module.
 */
interface EnrichmentQueryInterface
{
    /**
     * Find products that have pending or in-progress enrichment submissions,
     * for ONE tenant.
     *
     * `$tenantId` is REQUIRED rather than optional on purpose (2026-08-05
     * cat-(b) wave-1 review, B2). `products` is a tenant table, so under
     * database-per-tenant the bound connection already scopes the read and the
     * predicate is redundant-but-harmless; under the pre-flip compat mode
     * (`tenancy_resolver.db_per_tenant=false` — still the whole test suite's
     * mode) every tenant shares ONE database and the predicate is the only
     * thing that stops each tenant's poll from re-reading the same rows. A
     * defaulted/nullable parameter would make that difference silently
     * forgettable, which is exactly how this call site was missed.
     *
     * @return Collection<int, PendingEnrichmentDTO>
     */
    public function findPendingEnrichments(string $tenantId, int $limit, int $staleMinutes): Collection;

    /**
     * DRIFT PROBE — every pending submission visible ON THIS CONNECTION,
     * ignoring `tenant_id` entirely and ignoring the polling budget.
     *
     * N-6 (2026-08-05 re-gate). B2 gave the poller the tenant predicate its two
     * sibling conversions carry, but not the R1 drift SIGNAL that came with
     * them. `products.tenant_id` drifts exactly like `companies.tenant_id` — a
     * tenant database restored from another tenant's dump, a seeder default, a
     * mis-provisioned tenant — and a drifted product then leaves the polling
     * window SILENTLY: its submission is never re-checked and, because this
     * poller is the enrichment webhook's safety net, nothing else will pick it
     * up either.
     *
     * Meaningful only under database-per-tenant, where "this connection" IS
     * "this tenant". Under the compat mode the connection legitimately holds
     * the whole fleet, which is why the `WarnsOnTenantScopeDrift` console
     * concern never calls this there. It is a probe, NOT a read path — nothing may poll, enqueue or
     * mutate off its result.
     */
    public function countPendingEnrichmentsOnConnection(int $staleMinutes): int;

    /**
     * DRIFT PROBE — the same window as {@see self::countPendingEnrichmentsOnConnection()},
     * narrowed by the tenant predicate and still unbudgeted.
     *
     * The pair must differ ONLY in the `tenant_id` predicate: a probe that
     * applied the polling `limit`, or a different staleness window, would
     * report ordinary backlog as drift and make the signal noise.
     */
    public function countPendingEnrichmentsForTenant(string $tenantId, int $staleMinutes): int;

    /**
     * DRIFT PROBE — ids the tenant predicate excluded, capped by the caller
     * because drift can be fleet-sized.
     *
     * Only ever called once a delta is known to exist, so the expensive half of
     * the probe stays off the healthy path.
     *
     * @return list<string>
     */
    public function findPendingEnrichmentIdsOutsideTenant(string $tenantId, int $staleMinutes, int $limit): array;
}
