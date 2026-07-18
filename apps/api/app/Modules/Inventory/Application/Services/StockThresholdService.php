<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Shared\Domain\QuantityScale;
use Illuminate\Support\Facades\DB;

final class StockThresholdService
{
    /** @return array{product_id: string, variant_id: string|null, location_id: string, min_quantity: string|null, max_quantity: string|null} */
    public function update(
        string $tenantId,
        string $companyId,
        string $productId,
        ?string $variantId,
        string $locationId,
        ?string $minQuantity,
        ?string $maxQuantity,
    ): array {
        $query = DB::table('stock_levels')
            ->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->where('product_id', $productId)->where('location_id', $locationId);
        $variantId === null ? $query->whereNull('variant_id') : $query->where('variant_id', $variantId);
        $row = $query->first();
        $min = $minQuantity === null ? null : QuantityScale::round($minQuantity, QuantityScale::SCALE, QuantityScale::FLOOR);
        $max = $maxQuantity === null ? null : QuantityScale::round($maxQuantity, QuantityScale::SCALE, QuantityScale::FLOOR);
        if ($row === null) {
            DB::table('stock_levels')->insert([
                'id' => (string) str()->uuid(), 'tenant_id' => $tenantId, 'company_id' => $companyId,
                'product_id' => $productId, 'variant_id' => $variantId, 'location_id' => $locationId,
                'quantity' => '0.0000', 'reserved' => '0.0000', 'min_quantity' => $min, 'max_quantity' => $max,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $query->update(['min_quantity' => $min, 'max_quantity' => $max, 'updated_at' => now()]);
        }

        return ['product_id' => $productId, 'variant_id' => $variantId, 'location_id' => $locationId, 'min_quantity' => $min, 'max_quantity' => $max];
    }
}
