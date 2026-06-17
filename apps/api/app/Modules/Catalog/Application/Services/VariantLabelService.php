<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\DTOs\VariantLabelData;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Pricing\Domain\Services\PricingService;
use App\Modules\Product\Domain\Product;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Variant-label preparation service.
 *
 * Owns the "is this barcode value safe to assign/print?" collision rule and the
 * prepare() flow that turns a set of variant ids + quantities into
 * ready-to-print label payloads, lazily assigning a barcode (= the variant sku)
 * to variants that do not yet have one.
 */
final class VariantLabelService
{
    public function __construct(
        private readonly ProductVariantService $variantService,
        private readonly PricingService $pricingService,
        private readonly VariantLabelBarcodeRenderer $renderer,
    ) {}

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

    /**
     * Resolve a batch of {variantId, quantity} items into ready-to-print label
     * payloads, skipping any item that cannot be turned into a printable label.
     *
     * Per item:
     *   - The variant must exist within the company scope         → else skip `not_found`.
     *   - If it has no barcode, its sku is lazily assigned as the
     *     barcode, but only when that value does not collide with
     *     another variant/product (and the unique-constraint write
     *     succeeds)                                                → else skip `barcode_conflict`.
     *   - The effective price is resolved via PricingService.
     *
     * @param  array<int, array{variantId: string, quantity: int}>  $items
     * @return array{ready: array<int, VariantLabelData>, skipped: array<int, array{variant_id: string, reason: string}>}
     */
    public function prepare(Company $company, array $items): array
    {
        $ready = [];
        $skipped = [];

        foreach ($items as $item) {
            $variantId = $item['variantId'];
            $quantity = $item['quantity'];

            $variant = ProductVariant::query()
                ->where('tenant_id', $company->tenant_id)
                ->where('company_id', $company->id)
                ->with('product')
                ->find($variantId);

            if ($variant === null) {
                $skipped[] = ['variant_id' => $variantId, 'reason' => 'not_found'];

                continue;
            }

            if ($variant->barcode === null || $variant->barcode === '') {
                if (! $this->valueIsUsable($company->tenant_id, $variant->sku, $variant->id)) {
                    $skipped[] = ['variant_id' => $variantId, 'reason' => 'barcode_conflict'];

                    continue;
                }

                try {
                    $variant = $this->variantService->updateVariant($variant, ['barcode' => $variant->sku]);
                } catch (ValidationException) {
                    $skipped[] = ['variant_id' => $variantId, 'reason' => 'barcode_conflict'];

                    continue;
                }

                $variant->loadMissing('product');
            }

            $barcodeValue = (string) $variant->barcode;

            $price = $this->pricingService->getPrice(
                productId: $variant->product_id,
                partnerId: null,
                quantity: '1.00',
                currency: $company->currency,
                date: null,
                variantId: $variant->id,
            )['price'];

            $ready[] = new VariantLabelData(
                variant_id: $variant->id,
                product_name: $variant->product->name,
                name_suffix: $variant->name_suffix,
                effective_price: $price,
                barcode_value: $barcodeValue,
                symbology: $this->renderer->symbologyFor($barcodeValue),
                quantity: $quantity,
                sku: $variant->sku,
            );
        }

        return ['ready' => $ready, 'skipped' => $skipped];
    }
}
