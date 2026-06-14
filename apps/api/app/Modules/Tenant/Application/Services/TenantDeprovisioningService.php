<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Tenant\Presentation\Middleware\EnsureTenantIsActive;
use App\Providers\TenancyServiceProvider;
use App\Services\TenantTokenRevoker;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Psr\Log\LoggerInterface;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Throwable;

/**
 * T6 Phase 0b — tenant database lifecycle teardown (the provisioning counterpart).
 *
 * Provisioning (the flip test + the signup flow) creates the central `tenants`
 * row, the `domains` row, the `central_identities` pointer, then dispatches
 * Stancl's CreateDatabase + MigrateDatabase to build the physical per-tenant
 * database. This service is the reverse:
 *
 *   - {@see deprovision()} — the tenant is being DELETED/ARCHIVED. Its physical
 *     per-tenant database is dropped (DB-per-tenant mode ONLY) and every central
 *     directory row is removed (children before the tenant row, the same
 *     ordering the provisioning compensation path uses). Idempotent and safe:
 *     a tenant whose database was never provisioned deprovisions without error.
 *
 *   - {@see suspend()} — the tenant is being SUSPENDED. This is REVERSIBLE, so
 *     the database is NEVER dropped: only the status flips to Suspended.
 *     Access revocation is enforced at request time by
 *     {@see EnsureTenantIsActive},
 *     which already 403s any tenant whose status is not Active.
 *
 * Gating contract (CRITICAL): a physical DROP DATABASE is attempted ONLY when
 * `tenancy_resolver.db_per_tenant === true`. In shared-DB row-level compat mode
 * (dev/tests, pre-flip prod) there is no per-tenant database to drop — dropping
 * would target a non-existent (or the shared) database — so the physical-drop
 * path is skipped entirely and only the central rows are cleaned up. This
 * mirrors {@see TenancyServiceProvider}, which gates the Stancl
 * bootstrap on the same flag, and {@see TenancyResolver}.
 *
 * The drop is driven explicitly via Stancl's {@see DeleteDatabase} job (rather
 * than wiring it to the Eloquent `deleted` model event) for the same reason the
 * TenancyServiceProvider does NOT wire `TenantCreated -> CreateDatabase`: an
 * event listener would fire `DROP DATABASE` for EVERY tenant-row delete,
 * including compat-mode tests that delete tenant rows inside a transaction,
 * which PostgreSQL forbids. Explicit dispatch keeps teardown symmetric with
 * provisioning and under the flag's control.
 */
class TenantDeprovisioningService
{
    /**
     * Central directory tables, in delete order: every child row that points at
     * the tenant first, then the `tenants` row itself last. This matches the
     * "children before the tenant row" ordering of the provisioning
     * compensation path so a half-provisioned tenant tears down cleanly.
     *
     * @var list<string>
     */
    private const CENTRAL_CHILD_TABLES = [
        'domains',
        'central_identities',
        'tenant_subscriptions',
    ];

    public function __construct(
        private readonly Dispatcher $bus,
        private readonly ConnectionResolverInterface $db,
        private readonly LoggerInterface $logger,
        private readonly TenantTokenRevoker $tokenRevoker,
    ) {}

    /**
     * Tear down a deleted/archived tenant: revoke its users' central bearer
     * tokens, drop its physical database (DB mode only) and remove every central
     * directory row. Idempotent — calling it on a tenant whose database was
     * never provisioned, or calling it twice, does not error.
     */
    public function deprovision(Tenant $tenant): void
    {
        // Revoke the tenant users' central bearer tokens FIRST. This delete
        // path removes the central `tenants` row via the query builder, which
        // fires NO Eloquent model events, so the TenantObserver's revocation
        // never runs here — it must be invoked explicitly. Ordering is
        // critical: the revoker enumerates users inside $tenant->run() (needs
        // the tenant DB still up) with a central_identities fallback, and both
        // are torn down below, so revocation must precede the physical drop and
        // the central directory cleanup.
        $this->tokenRevoker->revokeTenantTokens($tenant);

        if ($this->isDatabasePerTenantMode()) {
            $this->dropPhysicalDatabaseIfPresent($tenant);
        }

        $this->removeCentralDirectoryRows($tenant);
    }

    /**
     * Suspend a tenant: REVERSIBLE, so the database is preserved. Only the
     * central status flips to Suspended; access is cut off at request time by
     * EnsureTenantIsActive. Idempotent — re-suspending an already-suspended
     * tenant is a no-op.
     */
    public function suspend(Tenant $tenant): void
    {
        if ($tenant->status === TenantStatus::Suspended) {
            return;
        }

        $tenant->status = TenantStatus::Suspended;
        $tenant->save();
    }

    private function isDatabasePerTenantMode(): bool
    {
        return (bool) config('tenancy_resolver.db_per_tenant', false);
    }

    /**
     * Drop the per-tenant physical database via Stancl's DeleteDatabase job,
     * but only if it actually exists. A tenant whose provisioning never reached
     * CreateDatabase (failed mid-flight, or an unclaimed pre-warm row) has no
     * database to drop, so this is a safe no-op.
     */
    private function dropPhysicalDatabaseIfPresent(Tenant $tenant): void
    {
        $databaseName = $tenant->database()->getName();

        if ($databaseName === null || $databaseName === '') {
            // No resolvable database name → nothing was ever provisioned.
            return;
        }

        try {
            $exists = $tenant->database()->manager()->databaseExists($databaseName);
        } catch (Throwable $e) {
            // An existence probe that itself fails (e.g. transient connection
            // fault) must not abort the central cleanup. Log and skip the drop.
            $this->logger->warning('Tenant database existence probe failed during deprovision; skipping physical drop.', [
                'tenant_id' => $tenant->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        if (! $exists) {
            return;
        }

        $this->bus->dispatchSync(new DeleteDatabase($tenant));
    }

    /**
     * Remove the central directory rows for a tenant — children first, then the
     * `tenants` row — on the central connection (which, in DB-per-tenant mode,
     * is the only connection that reaches these tables; in compat mode it is the
     * shared connection). Each delete is scoped to this tenant and is naturally
     * idempotent: a second call deletes zero rows.
     */
    private function removeCentralDirectoryRows(Tenant $tenant): void
    {
        $central = $this->db->connection($this->centralConnectionName());

        foreach (self::CENTRAL_CHILD_TABLES as $table) {
            $central->table($table)->where('tenant_id', $tenant->id)->delete();
        }

        $central->table('tenants')->where('id', $tenant->id)->delete();
    }

    /**
     * The named connection that always reaches the central directory tables,
     * mirroring Stancl's own `central_connection` config so "the app's central"
     * and "Stancl's central" never diverge.
     */
    private function centralConnectionName(): string
    {
        $name = config('tenancy.database.central_connection');

        return is_string($name) && $name !== '' ? $name : 'central';
    }
}
