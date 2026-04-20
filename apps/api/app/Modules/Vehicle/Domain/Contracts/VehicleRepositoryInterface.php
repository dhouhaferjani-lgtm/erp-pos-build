<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Contracts;

use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface VehicleRepositoryInterface
{
    public function findById(string $id): ?Vehicle;

    public function findByIdForTenant(string $id, string $tenantId): ?Vehicle;

    /**
     * Returns vehicle with the open ownership row loaded into `currentOwnership` relation.
     */
    public function findWithCurrentOwner(string $id): ?Vehicle;

    public function findForUpdate(string $id): Vehicle;

    /**
     * @return LengthAwarePaginator<int, Vehicle>
     */
    public function paginate(string $tenantId, string $companyId, ?string $search, int $perPage): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int, Vehicle>
     */
    public function paginateForOwner(string $tenantId, string $ownerPartnerId, int $perPage): LengthAwarePaginator;

    public function save(Vehicle $vehicle): void;
}
