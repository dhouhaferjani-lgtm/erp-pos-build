<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use App\Modules\Tenant\Application\Services\TenantDeprovisioningService;
use App\Modules\Tenant\Application\Services\TenantProvisioningService;
use App\Modules\Tenant\Domain\CentralIdentity;
use App\Modules\Tenant\Domain\Tenant;
use App\Observers\TenantObserver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revokes every CENTRAL Sanctum personal access token belonging to a tenant's
 * users (api.auth-permissions cluster, master plan §15 Invariants A.1 + A.2).
 *
 * Extracted out of {@see TenantObserver} so the SAME revocation
 * runs from every tenant-teardown path — including the two that delete the
 * central `tenants` row via the query builder and therefore fire NO Eloquent
 * model events (so the observer never runs there):
 *   - {@see TenantDeprovisioningService::deprovision()}
 *   - {@see TenantProvisioningService} failed-registration rollback
 * Both call this service explicitly.
 *
 * Topology (T6 Phase 0b): users live in the PER-TENANT database; token rows
 * live in the CENTRAL `personal_access_tokens` table. Enumeration of the
 * tenant's user ids therefore runs inside `$tenant->run()` (tenant DB), while
 * deletion runs from central context by (tokenable_type, tokenable_id) in
 * chunks of 200. If the tenant DB is unreachable — a broken/unprovisioned
 * tenant, or one whose physical database has already been dropped — it falls
 * back to the `central_identities` user_id pointers so revocation degrades
 * gracefully instead of aborting the suspend/delete/deprovision.
 *
 * Callers that destroy the tenant DB and/or the `central_identities` rows MUST
 * invoke this BEFORE that teardown, since both are the revoker's only sources
 * of the user-id set.
 */
class TenantTokenRevoker
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function revokeTenantTokens(Tenant $tenant): void
    {
        try {
            /** @var array<int, string> $userIds */
            $userIds = $tenant->run(
                static fn (): array => User::where('tenant_id', $tenant->id)->pluck('id')->all()
            );
        } catch (Throwable $e) {
            $this->logger->warning('Token revocation: tenant DB unreachable, using central identity index', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            /** @var array<int, string> $userIds */
            $userIds = CentralIdentity::where('tenant_id', $tenant->id)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->all();
        }

        if ($userIds === []) {
            return;
        }

        $morphClass = (new User)->getMorphClass();

        foreach (array_chunk($userIds, 200) as $chunk) {
            CentralPersonalAccessToken::query()
                ->where('tokenable_type', $morphClass)
                ->whereIn('tokenable_id', $chunk)
                ->delete();
        }
    }
}
