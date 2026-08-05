<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Console\TenantScopedCommand;
use App\Modules\Tenant\Domain\Tenant;
use RuntimeException;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;

/**
 * Trait for ShouldQueue jobs that touch per-tenant data.
 *
 * Rebinds tenant context for the duration of the closure passed to
 * {@see self::withTenantContext()}.
 *
 * **What the bind buys.** Under database-per-tenant, tenant context IS the
 * database selection: `Tenant::find($tenantId)->run()` initializes tenancy, so
 * the configured bootstrappers swap the default connection to that tenant's
 * physically separate database for the duration of the closure. This trait
 * makes the rebind explicit at the start of handle() so the worker's tenancy is
 * correct regardless of how the job entered the process. When the worker
 * auto-initializes tenancy from the queue payload's tenant_id (stancl's
 * QueueTenancyBootstrapper), the rebind is redundant but harmless; when the job
 * is re-hydrated from a `failed_jobs` row, manually re-queued, or dispatched
 * synchronously from a console/central context, the explicit rebind is the only
 * thing standing between the closure and the CENTRAL database.
 *
 * **Per-table `where('tenant_id', …)` scoping is the CONSUMER's choice, not a
 * mandate of this trait.** It is the right defense-in-depth for a job whose
 * tables carry a non-nullable `tenant_id` (the pattern established by
 * DispatchAppointmentReminder, api.console-commands.002). It is WRONG for
 * tables where the column is nullable or absent: SyncSellerListingsJob and
 * ReconcileListingsJob deliberately omit it because
 * `marketplace_sellers.tenant_id` is NULLABLE (external / Synerivia-owned
 * sellers carry NULL) and MarketplaceListing has no `company_id` at all — the
 * predicate would silently drop exactly the rows those jobs exist to process.
 * Each adopter documents its own choice on the class.
 *
 * **Property contract.** The using class MUST declare a `$tenantId` property
 * that survives serialize/restore. Prefer a declared (non-promoted), defaulted
 * `public ?string $tenantId = null;` when the class was ever dispatched WITHOUT
 * the anchor: `SerializesModels::__unserialize()` skips payload keys that are
 * absent, so a promoted `readonly string` would stay uninitialized and fatal on
 * first read ("Typed property must not be accessed before initialization"),
 * making pre-existing `failed_jobs` rows permanently un-retryable. Such a class
 * MUST handle the null case explicitly in handle() (discard-and-log, or
 * re-derive). A promoted `public readonly string $tenantId` remains correct for
 * jobs that have carried the anchor since their first dispatch.
 *
 * **Fail-loud semantics.** When `Tenant::find($this->tenantId)` returns null
 * this trait throws a RuntimeException that the queue worker surfaces as a job
 * failure, rather than silently processing under central context. Null means
 * the central `tenants` row is GONE — deprovisioning deletes it
 * (TenantDeprovisioningService), and `Tenant` uses no SoftDeletes. It does NOT
 * mean "suspended": `find()` applies no status predicate, so a Suspended tenant
 * (whose database is deliberately preserved, because suspension is reversible)
 * resolves normally and the job runs — which matches
 * {@see TenantScopedCommand::forEachTenant()}, whose only skip
 * predicate is database existence, not lifecycle status.
 *
 * @see Tenant
 * @see QueueTenancyBootstrapper
 */
trait BindsTenantContext
{
    /**
     * Run the closure under the dispatched tenant's bound context.
     * Throws when the tenant cannot be resolved (fail-loud).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    protected function withTenantContext(callable $fn): mixed
    {
        $tenant = Tenant::find($this->tenantId);
        if ($tenant === null) {
            throw new RuntimeException(sprintf(
                'BindsTenantContext: tenant "%s" not found — cannot rebind queue worker context. '.
                'Job class: %s. The central `tenants` row is gone (deprovisioned) or never existed — note that a '.
                'SUSPENDED tenant resolves normally, so suspension is NOT the cause; the queue worker is failing '.
                'the job rather than silently processing under an empty CompanyContext.',
                $this->tenantId,
                static::class,
            ));
        }

        return $tenant->run($fn);
    }
}
