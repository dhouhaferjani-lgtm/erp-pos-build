<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Modules\Tenant\Domain\Tenant;
use RuntimeException;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;

/**
 * Trait for ShouldQueue jobs that touch per-tenant data.
 *
 * Rebinds tenant context for the duration of the closure passed to
 * {@see self::withTenantContext()}. Two layers of defense apply:
 *
 *   (1) Stancl/tenancy schema swap via Tenant::find($tenantId)->run() —
 *       this trait makes the rebind explicit at the start of handle()
 *       so the worker's tenancy is correctly bound regardless of how
 *       the job entered the worker process. When the worker
 *       auto-initializes tenancy from the queue payload's tenant_id
 *       (via stancl's QueueTenancyBootstrapper), the rebind here is
 *       redundant but harmless. When invoked synchronously from tests
 *       or a non-queue dispatcher, this trait provides the same
 *       schema-level isolation.
 *
 *   (2) Inside the closure, the using class MUST also chain
 *       `->where('tenant_id', $this->tenantId)` on every query against
 *       a tenant-scoped table. This is the canonical
 *       defense-in-depth pattern established by DispatchAppointmentReminder
 *       (api.console-commands.002) — the explicit WHERE clause survives
 *       any schema-swap edge case (template_tenant_connection
 *       misconfiguration, partial migration state, central-only test
 *       fixtures, etc.) and is the contract the architecture test
 *       and per-cluster regression tests assert against.
 *
 * The using class MUST declare a `public readonly string $tenantId`
 * property (typically as a promoted constructor parameter) so the
 * queue payload carries the anchor that survives serialize/restore.
 *
 * Fail-loud semantics: when Tenant::find($this->tenantId) returns null
 * (the dispatched tenant has been soft-deleted, suspended, or never
 * existed), this trait throws a RuntimeException that the queue worker
 * surfaces as a job failure. The alternative — silently no-op'ing —
 * would mask invariant violations and leave dispatched work
 * permanently unprocessed without any visible error.
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
                'Job class: %s. The dispatched tenant may have been soft-deleted, suspended, or never existed; '.
                'the queue worker is failing the job rather than silently processing under an empty CompanyContext.',
                $this->tenantId,
                static::class,
            ));
        }

        return $tenant->run($fn);
    }
}
