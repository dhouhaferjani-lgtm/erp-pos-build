<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\StockLevel;
use App\Shared\Contracts\VariantStockReader;
use App\Shared\Domain\QuantityScale;

final class VariantStockReaderService implements VariantStockReader
{
    public function variantOnHandQuantity(string $tenantId, string $companyId, string $variantId): string
    {
        $sum = StockLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('variant_id', $variantId)
            ->sum('quantity');

        return QuantityScale::round((string) $sum, 4, QuantityScale::FLOOR);
    }
}
