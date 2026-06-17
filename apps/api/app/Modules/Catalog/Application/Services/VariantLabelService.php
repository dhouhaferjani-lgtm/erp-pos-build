<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Product\Domain\Product;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Variant-label preparation service.
 *
 * Owns the "is this barcode value safe to assign/print?" collision rule and
 * (Task B2) the prepare() flow that turns a set of variant ids + quantities
 * into ready-to-print label payloads.
 */
final class VariantLabelService
{
    /**
     * A candidate value is usable iff it is NOT already claimed, within the
     * tenant, by:
     *   - another active (non-deleted) variant's barcode, or
     *   - any product's barcode OR sku.
     *
     * The owning variant ($exceptVariantId) is excluded so re-assigning a
     * variant's own value never reports a self-collision.
     */
    public function valueIsUsable(string $tenantId, string $value, string $exceptVariantId): bool
    {
        $variantClash = ProductVariant::query()
            ->where('tenant_id', $tenantId)
            ->where('barcode', $value)
            ->where('id', '!=', $exceptVariantId)
            ->whereNull('deleted_at')
            ->exists();

        if ($variantClash) {
            return false;
        }

        $productClash = Product::query()
            ->where('tenant_id', $tenantId)
            ->where(fn (Builder $q): Builder => $q->where('barcode', $value)->orWhere('sku', $value))
            ->exists();

        return ! $productClash;
    }
}
