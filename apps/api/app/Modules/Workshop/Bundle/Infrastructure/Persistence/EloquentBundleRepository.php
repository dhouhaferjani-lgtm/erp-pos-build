<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Infrastructure\Persistence;

use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EloquentBundleRepository implements BundleRepositoryInterface
{
    /**
     * Case-insensitive LIKE operator: `ilike` on PostgreSQL, `like` elsewhere.
     * Tests use SQLite which doesn't support ilike.
     */
    private function caseInsensitiveLike(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ilike' : $this->caseInsensitiveLike();
    }

    public function findById(string $bundleId): ?ServiceBundle
    {
        return ServiceBundle::query()->find($bundleId);
    }

    public function findWithComponentsAndApplicabilities(string $bundleId): ?ServiceBundle
    {
        return ServiceBundle::query()
            ->with(['components', 'vehicleApplicabilities'])
            ->find($bundleId);
    }

    /**
     * @param  array{active?: bool, search?: string}  $filters
     * @return LengthAwarePaginator<int, ServiceBundle>
     */
    public function paginateForCompany(string $tenantId, string $companyId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ServiceBundle::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->orderBy('name');

        if (isset($filters['active'])) {
            $query->where('is_active', $filters['active']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', $this->caseInsensitiveLike(), $search)
                    ->orWhere('code', $this->caseInsensitiveLike(), $search);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * @return Collection<int, ServiceBundle>
     */
    public function applicableForVehicle(
        string $tenantId,
        string $companyId,
        ?string $platformVehicleId,
        ?string $vehicleType,
        ?string $searchText,
    ): Collection {
        $query = ServiceBundle::query()
            ->with(['components', 'vehicleApplicabilities'])
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true);

        $query->where(function (Builder $q) use ($platformVehicleId, $vehicleType): void {
            // Universal applicabilities always match.
            $q->whereHas('vehicleApplicabilities', function (Builder $inner): void {
                $inner->whereNull('platform_vehicle_id')->whereNull('vehicle_type');
            });

            if ($platformVehicleId !== null && $vehicleType !== null) {
                $q->orWhereHas('vehicleApplicabilities', function (Builder $inner) use ($platformVehicleId, $vehicleType): void {
                    $inner->getQuery()
                        ->where('platform_vehicle_id', $platformVehicleId)
                        ->where('vehicle_type', $vehicleType);
                });
            }
        });

        if ($searchText !== null && $searchText !== '') {
            $search = '%'.$searchText.'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', $this->caseInsensitiveLike(), $search)
                    ->orWhere('code', $this->caseInsensitiveLike(), $search)
                    ->orWhere('description', $this->caseInsensitiveLike(), $search);
            });
        }

        return $query->orderBy('name')->get();
    }

    public function save(ServiceBundle $bundle): ServiceBundle
    {
        $bundle->save();

        return $bundle;
    }

    public function softDelete(ServiceBundle $bundle): void
    {
        $bundle->delete();
    }
}
