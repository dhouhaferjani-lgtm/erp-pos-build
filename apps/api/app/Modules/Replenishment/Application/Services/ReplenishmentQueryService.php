<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Application\Services;

use App\Modules\Inventory\Application\Services\ReplenishmentSuggestionService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ReplenishmentQueryService
{
    public function __construct(
        private readonly ReplenishmentSuggestionService $suggestionService,
    ) {}

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
        $rows = $this->responseQuery()
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

        $feedRows = $rows->take($cap)->values();
        $openRows = $feedRows->filter(
            static fn (ReplenishmentRequest $row): bool => in_array($row->status, [
                ReplenishmentStatus::Pending,
                ReplenishmentStatus::InProgress,
            ], true),
        );
        $suggestions = $this->suggestionService->suggestionsForLocation(
            $tenantId,
            $companyId,
            $locationId,
            array_values($openRows->map(static fn (ReplenishmentRequest $row): array => [
                'product_id' => $row->product_id,
                'variant_id' => $row->variant_id,
            ])->all()),
        );

        foreach ($feedRows as $row) {
            $row->suggested_qty = in_array($row->status, [
                ReplenishmentStatus::Pending,
                ReplenishmentStatus::InProgress,
            ], true)
                ? $suggestions[ReplenishmentSuggestionService::grainKey($row->product_id, $row->variant_id)]
                : null;
        }

        return [
            'rows' => $feedRows,
            'truncated' => $rows->count() > $cap,
        ];
    }

    public function findForCompany(
        string $tenantId,
        string $companyId,
        string $requestId,
    ): ReplenishmentRequest {
        return $this->responseQuery()
            ->where('replenishment_requests.tenant_id', $tenantId)
            ->where('replenishment_requests.company_id', $companyId)
            ->where('replenishment_requests.id', $requestId)
            ->firstOrFail();
    }

    /** @return Builder<ReplenishmentRequest> */
    private function responseQuery(): Builder
    {
        return ReplenishmentRequest::query()
            ->with(['product.unitOfMeasure'])
            ->leftJoin('locations', 'locations.id', '=', 'replenishment_requests.location_id')
            ->leftJoin('products', 'products.id', '=', 'replenishment_requests.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'replenishment_requests.variant_id')
            ->select([
                'replenishment_requests.*',
                'locations.name as location_name',
                'products.name as product_name',
                'product_variants.name_suffix as variant_name',
            ]);
    }
}
