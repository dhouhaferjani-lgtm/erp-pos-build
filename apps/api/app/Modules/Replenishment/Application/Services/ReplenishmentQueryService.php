<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Application\Services;

use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use Illuminate\Support\Collection;

final class ReplenishmentQueryService
{
    /**
     * @return array{rows: Collection<int, ReplenishmentRequest>, truncated: bool}
     */
    public function feedForLocation(
        string $tenantId,
        string $companyId,
        string $locationId,
        int $closedWithinDays = 14,
        int $cap = 200,
    ): array {
        $rows = ReplenishmentRequest::query()
            ->leftJoin('locations', 'locations.id', '=', 'replenishment_requests.location_id')
            ->leftJoin('products', 'products.id', '=', 'replenishment_requests.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'replenishment_requests.variant_id')
            ->select([
                'replenishment_requests.*',
                'locations.name as location_name',
                'products.name as product_name',
                'product_variants.name_suffix as variant_name',
            ])
            ->where('replenishment_requests.tenant_id', $tenantId)
            ->where('replenishment_requests.company_id', $companyId)
            ->where('replenishment_requests.location_id', $locationId)
            ->where(function ($query) use ($closedWithinDays): void {
                $query->whereIn('replenishment_requests.status', [
                    ReplenishmentStatus::Pending,
                    ReplenishmentStatus::InProgress,
                ])->orWhere(function ($closed) use ($closedWithinDays): void {
                    $closed->whereIn('replenishment_requests.status', [
                        ReplenishmentStatus::Fulfilled,
                        ReplenishmentStatus::Rejected,
                        ReplenishmentStatus::Cancelled,
                    ])->where(
                        'replenishment_requests.processed_at',
                        '>=',
                        now()->subDays($closedWithinDays),
                    );
                });
            })
            ->orderByDesc('replenishment_requests.last_requested_at')
            ->limit($cap + 1)
            ->get();

        return [
            'rows' => $rows->take($cap)->values(),
            'truncated' => $rows->count() > $cap,
        ];
    }
}
