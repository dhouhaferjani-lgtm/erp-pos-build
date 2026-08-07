<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Infrastructure\Notifications;

use App\Modules\Identity\Domain\User;
use App\Modules\SupportAccess\Application\DTOs\SupportAccessNotificationData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\SupportAccess\SupportAccessNotifier;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

final class TenantDatabaseSupportAccessNotifier implements SupportAccessNotifier
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function grantRequested(ImpersonationGrant $grant): void
    {
        $tenant = Tenant::query()->findOrFail($grant->tenant_id);
        $notify = function () use ($grant): void {
            Notification::send(
                $this->managersOnCurrentConnection($grant->tenant_id),
                new SupportAccessGrantNotification(
                    new SupportAccessNotificationData(
                        grant_id: $grant->id,
                        tenant_id: $grant->tenant_id,
                        event: 'grant_requested',
                        ticket_ref: $grant->ticket_ref,
                    ),
                ),
            );
        };
        $central = $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        );

        if (! (bool) $this->config->get('tenancy_resolver.db_per_tenant', false)
            && $central->getSchemaBuilder()->hasTable('users')) {
            $notify();

            return;
        }

        $tenant->run($notify);
    }

    /** @return Collection<int, User> */
    private function managersOnCurrentConnection(string $tenantId): Collection
    {
        $previousTeamId = $this->permissions->getPermissionsTeamId();
        $this->permissions->setPermissionsTeamId($tenantId);

        try {
            return User::query()
                ->where('tenant_id', $tenantId)
                ->get()
                ->filter(static fn (User $user): bool => $user->hasPermissionTo('support-access.manage'))
                ->values();
        } finally {
            $this->permissions->setPermissionsTeamId($previousTeamId);
        }
    }
}
