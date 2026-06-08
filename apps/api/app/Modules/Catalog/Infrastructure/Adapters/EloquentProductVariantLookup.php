<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Adapters;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\DTOs\ProductVariantSummary;
use Illuminate\Support\Collection;

/**
 * Eloquent-backed implementation of the cross-module ProductVariantLookup contract.
 *
 * This adapter lives inside the Catalog module's Infrastructure layer and is the
 * only place that may reference the ProductVariant Eloquent model from the lookup
 * path. All callers outside Catalog must use the ProductVariantLookup interface.
 */
final readonly class EloquentProductVariantLookup implements ProductVariantLookup
{
    public function findById(string $id): ?ProductVariantSummary
    {
        return $this->toSummary(ProductVariant::find($id));
    }

    public function findByBarcode(string $barcode, string $companyId): ?ProductVariantSummary
    {
        return $this->toSummary(
            ProductVariant::where('barcode', $barcode)
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->first()
        );
    }

    public function findBySku(string $sku, string $companyId): ?ProductVariantSummary
    {
        return $this->toSummary(
            ProductVariant::where('sku', $sku)
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->first()
        );
    }

    /**
     * @return Collection<int, ProductVariantSummary>
     */
    public function listForProduct(string $productId, bool $onlyActive = true): Collection
    {
        $query = ProductVariant::where('product_id', $productId);

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        /** @var Collection<int, ProductVariant> $variants */
        $variants = $query->orderBy('display_order')->get();

        return $variants->map(fn (ProductVariant $v): ProductVariantSummary => $this->variantToSummary($v));
    }

    private function toSummary(?ProductVariant $v): ?ProductVariantSummary
    {
        if ($v === null) {
            return null;
        }

        return $this->variantToSummary($v);
    }

    private function variantToSummary(ProductVariant $v): ProductVariantSummary
    {
        // Monetary columns (price_override, cost_override) are intentionally
        // left uncast in the Eloquent model to preserve decimal string precision.
        // On SQLite the driver may return an int for whole-number decimals; cast
        // to string here so the DTO contract (?string) is always satisfied.
        $priceOverride = $v->price_override !== null ? (string) $v->price_override : null;
        $costOverride = $v->cost_override !== null ? (string) $v->cost_override : null;

        return new ProductVariantSummary(
            id: $v->id,
            productId: $v->product_id,
            tenantId: $v->tenant_id,
            companyId: $v->company_id,
            sku: $v->sku,
            variantCode: $v->variant_code,
            barcode: $v->barcode,
            nameSuffix: $v->name_suffix,
            isDefault: $v->is_default,
            isActive: $v->is_active,
            priceOverride: $priceOverride,
            costOverride: $costOverride,
            imageUrl: $v->image_url,
        );
    }
}
