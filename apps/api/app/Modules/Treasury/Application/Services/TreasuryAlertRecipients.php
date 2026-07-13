<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
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
                ->whereHas('companyMemberships', $this->activeCompanyMemberships($companyId))
                ->permission($permission)
                ->get();
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($originalTeamId);
        }
    }

    /** @return Closure(Builder<UserCompanyMembership>): void */
    private function activeCompanyMemberships(string $companyId): Closure
    {
        $scope = new class($companyId)
        {
            public function __construct(
                private readonly string $companyId,
            ) {}

            /** @param Builder<UserCompanyMembership> $query */
            public function __invoke(Builder $query): void
            {
                $query->where('company_id', $this->companyId)
                    ->where('status', 'active');
            }
        };

        return Closure::fromCallable($scope);
    }
}
