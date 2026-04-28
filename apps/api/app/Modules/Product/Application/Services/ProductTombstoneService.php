<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Domain\Product;
use Carbon\Carbon;

final class ProductTombstoneService
{
    /**
     * Resolve product IDs soft-deleted for the given company/tenant since the cursor.
     *
     * @return array<int, string>
     */
    public function idsDeletedSince(string $tenantId, string $companyId, Carbon $cursor): array
    {
        return Product::onlyTrashed()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('deleted_at', '>=', $cursor)
            ->pluck('id')
            ->all();
    }
}
