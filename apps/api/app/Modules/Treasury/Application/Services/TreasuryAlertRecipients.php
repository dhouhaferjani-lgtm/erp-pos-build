<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Identity\Domain\User;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

final class TreasuryAlertRecipients
{
    public function __construct(
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function forCompany(
        string $tenantId,
        string $companyId,
        string $permission = 'treasury.manage',
    ): Collection {
        $originalTeamId = $this->permissionRegistrar->getPermissionsTeamId();

        try {
            $this->permissionRegistrar->setPermissionsTeamId($tenantId);
            $this->permissionRegistrar->forgetCachedPermissions();

            return User::query()
                ->where('tenant_id', $tenantId)
                ->whereHas('companyMemberships', function ($query) use ($companyId): void {
                    $query->where('company_id', $companyId)
                        ->where('status', 'active');
                })
                ->permission($permission)
                ->get();
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($originalTeamId);
        }
    }
}
