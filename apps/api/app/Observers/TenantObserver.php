<?php

declare(strict_types=1);

namespace App\Observers;

use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use App\Services\TenantTokenRevoker;

/**
 * Observer for Tenant model — cache invalidation + Sanctum token revocation
 * lifecycle (api.auth-permissions cluster, master plan §15 Invariants A.1 + A.2).
 *
 * Cache invalidation: when vertical or enabled_extras changes, the tenant
 * config cache must be invalidated so CompanyConfigService rebuilds with
 * the new settings on the next request.
 *
 * Token revocation (Invariants A.1 + A.2):
 *   - On status transition to Suspended: every user in the tenant has their
 *     personal_access_tokens revoked synchronously. This blocks suspended-
 *     tenant users from continuing to authenticate with pre-suspension
 *     bearer tokens. Race window between status-commit and token-revocation
 *     is acceptable per master plan §15 (sub-second window).
 *   - On Tenant::deleting / ::forceDeleted: tokens are revoked BEFORE the
 *     users.tenant_id ON DELETE CASCADE fires. Eloquent User events do NOT
 *     fire on DB-level cascade, so the hook MUST live on Tenant lifecycle.
 *     Sanctum personal_access_tokens has polymorphic FK (tokenable_type +
 *     tokenable_id), NOT a hard FK to users.id, so DB cascade does not
 *     transitively purge tokens — explicit revocation is required.
 *
 * The revocation mechanics (per-tenant DB user enumeration with a
 * central_identities fallback, chunked central deletion) live in
 * {@see TenantTokenRevoker}, shared with the query-builder teardown paths
 * (deprovision + failed-registration rollback) that fire no Eloquent events.
 */
class TenantObserver
{
    public function __construct(
        private readonly CompanyConfigService $companyConfigService,
        private readonly TenantTokenRevoker $tokenRevoker,
    ) {}

    /**
     * Handle the Tenant "updated" event.
     *
     * (1) Invalidates tenant config cache when vertical or enabled_extras changes.
     * (2) Revokes all user tokens when status transitions to Suspended (Invariant A.1).
     */
    public function updated(Tenant $tenant): void
    {
        if ($tenant->wasChanged(['vertical', 'enabled_extras'])) {
            $this->invalidateTenantConfigCache($tenant);
        }

        if ($tenant->wasChanged('status') && $tenant->status === TenantStatus::Suspended) {
            $this->tokenRevoker->revokeTenantTokens($tenant);
        }
    }

    /**
     * Handle the Tenant "deleting" event (Invariant A.2).
     *
     * Hook fires BEFORE the users.tenant_id ON DELETE CASCADE so the
     * personal_access_tokens (polymorphic FK; not cascade-transitive) are
     * revoked while users are still queryable by tenant_id.
     */
    public function deleting(Tenant $tenant): void
    {
        $this->tokenRevoker->revokeTenantTokens($tenant);
    }

    /**
     * Handle the Tenant "forceDeleted" event (Invariant A.2 forward-compat).
     *
     * Tenant does NOT use SoftDeletes today; this method is forward-compat
     * defense if the trait is added later. Cost is one method.
     */
    public function forceDeleted(Tenant $tenant): void
    {
        $this->tokenRevoker->revokeTenantTokens($tenant);
    }

    /**
     * Invalidate tenant config cache.
     *
     * Since all companies within a tenant share the same vertical and extras,
     * we only need to invalidate a single cache entry per tenant. The cache
     * key is owned by CompanyConfigService — never hand-build it here.
     */
    private function invalidateTenantConfigCache(Tenant $tenant): void
    {
        $this->companyConfigService->invalidateForTenant($tenant->id);
    }
}
