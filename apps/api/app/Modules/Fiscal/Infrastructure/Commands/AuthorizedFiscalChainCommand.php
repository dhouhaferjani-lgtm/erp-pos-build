<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Shared actor gate for fiscal chain verification commands.
 *
 * Callers must invoke this only after binding the manifest/request tenant:
 * `users` and Spatie's team-scoped permission tables are tenant data.
 */
abstract class AuthorizedFiscalChainCommand extends TenantScopedCommand
{
    public function __construct(
        CompanyContext $companyContext,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {
        parent::__construct($companyContext);
    }

    protected function authorizeBoundTenantActor(string $actorId, string $tenantId): int
    {
        try {
            $actor = User::query()->where('tenant_id', $tenantId)->find($actorId);
        } catch (Throwable $e) {
            Log::critical('Fiscal chain verification: actor lookup failed; cannot evaluate the permission gate.', [
                'command' => static::class,
                'actor_id' => $actorId,
                'tenant_id' => $tenantId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->error('Could not read the actor for the permission gate (transient failure); re-run to retry.');

            return 2;
        }

        if ($actor === null) {
            $this->error(sprintf('Unknown actor user id %s.', $actorId));

            return self::FAILURE;
        }

        $previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();
        try {
            $this->permissionRegistrar->setPermissionsTeamId($actor->tenant_id);

            if (! $actor->can('fiscal.events.verify_chain')) {
                $this->error(sprintf(
                    'Actor %s lacks the fiscal.events.verify_chain permission.',
                    $actorId,
                ));

                return self::FAILURE;
            }
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);
        }

        return self::SUCCESS;
    }
}
