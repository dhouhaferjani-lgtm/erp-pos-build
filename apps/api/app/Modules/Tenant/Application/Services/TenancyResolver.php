<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Console\TenantScopedCommand;
use App\Modules\Identity\Presentation\Middleware\ResolveTenancy;
use App\Modules\Tenant\Domain\Exceptions\TenantUnavailableException;
use App\Modules\Tenant\Domain\Tenant;
use Throwable;

/**
 * Flip-agnostic tenancy initialization (topology §9.4).
 *
 * Phase 0a runs against the CURRENT setup where every tenant's data lives in
 * the shared `public` schema and no per-tenant schema/database has been
 * provisioned. `PostgreSQLSchemaManager` sets `search_path = <tenantSchema>`
 * with NO public fallback, so an UNCONDITIONAL `tenancy()->initialize()` would
 * point queries at a non-existent schema and break every request.
 *
 * This resolver therefore only switches the connection when the tenant's
 * database/schema actually exists:
 *
 *   - Today (no per-tenant schema): {@see initializeIfProvisioned()} is a no-op
 *     for the DB switch; callers keep querying the shared DB scoped by
 *     `tenant_id`, which is equally correct.
 *   - Post-flip (real per-tenant DB, Phase 0b): the schema/DB exists, so it
 *     fully initializes and queries hit the tenant DB.
 *
 * Same code, no logic change between phases — which is the whole point of the
 * "flip-agnostic" Phase 0a split.
 *
 * **Lifecycle STATUS is deliberately not a predicate here — R3, 2026-08-05
 * cat-(b) wave-1 review.** The only gate is database existence. That is a real
 * policy DIVERGENCE from the authenticated request path, which rejects
 * Suspended and Archived tenants outright
 * ({@see ResolveTenancy}), and it
 * is intended, not an oversight:
 *
 *   - Suspension is REVERSIBLE and the tenant database is preserved on purpose
 *     (`TenantDeprovisioningService` is what actually removes a tenant; that
 *     drops the central row, so `initializeIfProvisioned()` is never reached).
 *   - The callers of THIS resolver are the data plane — the unauthenticated
 *     channel webhook, queue workers via `BindsTenantContext`, and the
 *     per-tenant scheduler iteration in
 *     {@see TenantScopedCommand::forEachTenant()}. Their traffic
 *     is machine-originated and NOT redelivered: an external sales platform
 *     that gets a 404 drops the order permanently. Refusing it would destroy
 *     data the tenant is entitled to on reinstatement, and would silently
 *     disable every batch control (cash-drift freeze, fiscal dead-letter
 *     recovery, batch-expiry alerts) on a tenant whose database is still live.
 *   - `ResolveTenancy` governs the CONTROL plane — a human logging in. Locking
 *     the tenant's users out while its machine traffic keeps landing is the
 *     intended asymmetry: suspension is a commercial lever, not a data-retention
 *     decision.
 *
 * A tenant whose ingestion genuinely must stop is deprovisioned (central row
 * gone) or has its database taken offline — both of which this resolver already
 * answers, fail-closed.
 */
class TenancyResolver
{
    /**
     * Initialize tenancy for the tenant IF its database/schema has been
     * provisioned. Returns true when the connection was switched, false when
     * the tenant is not yet provisioned (current single-schema reality).
     *
     * P1-4 (Codex 2026-05-25): the silent fall-back to the un-switched
     * connection is gated behind the explicit single-schema-compat flag
     * (`tenancy_resolver.db_per_tenant === false`, the Phase 0a default). When
     * DB-per-tenant mode is active (Phase 0b), a PRESENT tenant whose database
     * does not exist or fails to initialize FAILS CLOSED
     * ({@see TenantUnavailableException} -> 503) before any downstream
     * middleware, so the request never authenticates/queries against the wrong
     * connection.
     *
     * @throws TenantUnavailableException when DB-per-tenant mode is active and a
     *                                    present tenant cannot be initialized.
     */
    public function initializeIfProvisioned(Tenant $tenant): bool
    {
        $dbPerTenant = (bool) config('tenancy_resolver.db_per_tenant', false);

        try {
            if (tenancy()->initialized && tenant()?->getTenantKey() === $tenant->getTenantKey()) {
                return true;
            }

            if (! $tenant->database()->manager()->databaseExists($tenant->getDatabaseName())) {
                if ($dbPerTenant) {
                    // DB mode: a present tenant with no provisioned database must
                    // not silently continue on the default connection.
                    throw new TenantUnavailableException(
                        'Tenant database is not provisioned.',
                    );
                }

                // Single-schema compat (Phase 0a): no per-tenant DB exists yet,
                // so the DB switch is a deliberate no-op; callers keep querying
                // the shared DB scoped by tenant_id.
                return false;
            }

            tenancy()->initialize($tenant);

            return true;
        } catch (TenantUnavailableException $e) {
            // Already a fail-closed signal — re-throw unchanged.
            throw $e;
        } catch (Throwable $e) {
            if ($dbPerTenant) {
                // DB mode: an existence-probe / initialization fault must fail
                // closed rather than degrade to the wrong connection.
                throw new TenantUnavailableException(
                    'Tenant could not be initialized.',
                    $e,
                );
            }

            // Single-schema compat: a resolver must never take down a request
            // today — fall back to the un-switched (shared) connection.
            return false;
        }
    }
}
