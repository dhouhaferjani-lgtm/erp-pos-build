<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\Jobs\ApplyCatalogEnrichmentJob;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Str;

final class CatalogBacklinkDispatcher
{
    public function productExists(string $productId, string $companyId, string $tenantId): bool
    {
        return Product::query()
            ->where('id', $productId)
            ->where('company_id', $companyId)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    public function hasBacklink(string $productId, string $companyId, string $tenantId): bool
    {
        return Product::query()
            ->where('id', $productId)
            ->where('company_id', $companyId)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('platform_product_id')
            ->exists();
    }

    public function linkAndApply(
        string $productId,
        string $companyId,
        string $tenantId,
        string $platformProductId,
        string $barcode,
        string $vertical,
    ): bool {
        if (! Str::isUuid($platformProductId)) {
            return false;
        }

        $updated = Product::query()
            ->where('id', $productId)
            ->where('company_id', $companyId)
            ->where('tenant_id', $tenantId)
            ->update(['platform_product_id' => $platformProductId]);

        if ($updated !== 1) {
            return false;
        }

        ApplyCatalogEnrichmentJob::dispatch(
            productId: $productId,
            expectedPlatformProductId: $platformProductId,
            barcode: $barcode,
            vertical: $vertical,
            tenantId: $tenantId,
        )->onQueue('enrichment');

        return true;
    }
}
