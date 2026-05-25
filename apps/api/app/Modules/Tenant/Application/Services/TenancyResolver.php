<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

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
 */
class TenancyResolver
{
    /**
     * Initialize tenancy for the tenant IF its database/schema has been
     * provisioned. Returns true when the connection was switched, false when
     * the tenant is not yet provisioned (current single-schema reality).
     */
    public function initializeIfProvisioned(Tenant $tenant): bool
    {
        try {
            if (tenancy()->initialized && tenant()?->getTenantKey() === $tenant->getTenantKey()) {
                return true;
            }

            if (! $tenant->database()->manager()->databaseExists($tenant->getDatabaseName())) {
                return false;
            }

            tenancy()->initialize($tenant);

            return true;
        } catch (Throwable) {
            // A resolver must never take down a request: if the existence probe
            // or initialization fails, fall back to the un-switched connection.
            return false;
        }
    }
}
