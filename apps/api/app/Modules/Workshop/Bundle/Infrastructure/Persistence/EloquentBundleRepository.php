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
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true);

        // Two-step applicability match: get IDs of bundles whose
        // applicability set includes universal OR the specific vehicle,
        // then filter the main query by that set. This avoids nested
        // whereHas + `like` combinations that SQLite's query planner
        // pathologizes into runaway work loads during tests.
        $applicabilityQuery = DB::table('workshop_service_bundle_vehicle_applicabilities')
            ->select('bundle_id')
            ->where(function ($q) use ($platformVehicleId, $vehicleType): void {
                $q->where(function ($inner): void {
                    $inner->whereNull('platform_vehicle_id')->whereNull('vehicle_type');
                });
                if ($platformVehicleId !== null && $vehicleType !== null) {
                    $q->orWhere(function ($inner) use ($platformVehicleId, $vehicleType): void {
                        $inner->where('platform_vehicle_id', $platformVehicleId)
                            ->where('vehicle_type', $vehicleType);
                    });
                }
            });

        $query->whereIn('id', $applicabilityQuery);

        if ($searchText !== null && $searchText !== '') {
            $op = $this->caseInsensitiveLike();
            $search = '%'.$searchText.'%';
            $query->where(function (Builder $q) use ($search, $op): void {
                $q->where('name', $op, $search)
                    ->orWhere('code', $op, $search);
            });
        }

        return $query->orderBy('name')->with(['components', 'vehicleApplicabilities'])->get();
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
