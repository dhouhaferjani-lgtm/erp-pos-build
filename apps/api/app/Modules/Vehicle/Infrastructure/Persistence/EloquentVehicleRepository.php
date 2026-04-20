<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Infrastructure\Persistence;

use App\Modules\Vehicle\Domain\Contracts\VehicleRepositoryInterface;
use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentVehicleRepository implements VehicleRepositoryInterface
{
    public function findById(string $id): ?Vehicle
    {
        return Vehicle::query()->find($id);
    }

    public function findByIdForTenant(string $id, string $tenantId): ?Vehicle
    {
        return Vehicle::query()->forTenant($tenantId)->find($id);
    }

    public function findWithCurrentOwner(string $id): ?Vehicle
    {
        return Vehicle::query()
            ->with(['currentOwnership.ownerPartner'])
            ->find($id);
    }

    public function findForUpdate(string $id): Vehicle
    {
        return Vehicle::query()->lockForUpdate()->findOrFail($id);
    }

    public function paginate(string $tenantId, string $companyId, ?string $search, int $perPage): LengthAwarePaginator
    {
        $query = Vehicle::query()
            ->forTenant($tenantId)
            ->forCompany($companyId)
            ->with(['currentOwnership.ownerPartner']);

        if ($search !== null && $search !== '') {
            $likeOperator = $this->likeOperator();
            $query->where(function (Builder $q) use ($search, $likeOperator): void {
                $q->where('license_plate', $likeOperator, "%{$search}%")
                    ->orWhere('vin', $likeOperator, "%{$search}%")
                    ->orWhere('brand', $likeOperator, "%{$search}%")
                    ->orWhere('model', $likeOperator, "%{$search}%");
            });
        }

        return $query->orderByDesc('updated_at')->paginate($perPage);
    }

    public function paginateForOwner(string $tenantId, string $ownerPartnerId, int $perPage): LengthAwarePaginator
    {
        return Vehicle::query()
            ->forTenant($tenantId)
            ->whereHas('currentOwnership', function ($q) use ($ownerPartnerId): void {
                /** @phpstan-ignore argument.type */
                $q->where('owner_partner_id', $ownerPartnerId);
            })
            ->with(['currentOwnership.ownerPartner'])
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    public function save(Vehicle $vehicle): void
    {
        $vehicle->save();
    }

    /**
     * PostgreSQL supports ILIKE (case-insensitive); other drivers fall back to LIKE.
     */
    private function likeOperator(): string
    {
        $driver = DB::connection()->getDriverName();

        return $driver === 'pgsql' ? 'ilike' : 'like';
    }
}
