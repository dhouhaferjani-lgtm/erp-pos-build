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
     *   - any variant's barcode (INCLUDING soft-deleted variants), or
     *   - any product's barcode OR sku.
     *
     * Soft-deleted variants are deliberately INCLUDED (withTrashed): a stale
     * offline POS device may still hold the deleted variant row, so reusing its
     * barcode would cause that device to mis-scan. A deleted variant's barcode
     * is therefore treated as STILL TAKEN, not reusable.
     *
     * The owning variant ($exceptVariantId) is excluded so re-assigning a
     * variant's own value never reports a self-collision.
     */
    public function valueIsUsable(string $tenantId, string $value, string $exceptVariantId): bool
    {
        $variantClash = ProductVariant::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('barcode', $value)
            ->where('id', '!=', $exceptVariantId)
            ->exists();

        if ($variantClash) {
            return false;
        }

        return ! $this->collidesWithProductCode($tenantId, $value);
    }

    /**
     * Whether $value collides with a product code (barcode OR sku) of any
     * Product in the tenant.
     *
     * Spec A resolves a scanned code against product barcode/sku in Tier 1,
     * BEFORE the variant tier. A variant label whose barcode equals a product
     * code therefore mis-scans to that product, so such a value must never be
     * assigned to — nor printed for — a variant.
     *
     * Unlike {@see self::valueIsUsable()}, this intentionally excludes the
     * other-variant-barcode (withTrashed) subcheck: a variant legitimately owns
     * its own existing barcode, and a dead variant also holding it must not
     * false-skip the live owner. Use this directly when re-validating a barcode
     * the variant already owns.
     */
    public function collidesWithProductCode(string $tenantId, string $value): bool
    {
        return Product::query()
            ->where('tenant_id', $tenantId)
            ->where(fn (Builder $q): Builder => $q->where('barcode', $value)->orWhere('sku', $value))
            ->exists();
    }

    /**
     * Resolve a batch of {variantId, quantity} items into ready-to-print label
     * payloads, skipping any item that cannot be turned into a printable label.
     *
     * Per item:
     *   - The variant must exist within the company scope         → else skip `not_found`.
     *   - Its parent product must still exist (the variant row can
     *     survive a soft-deleted product)                          → else skip `product_unavailable`.
     *   - If it has no barcode, its sku is lazily assigned as the
     *     barcode, but only when that value does not collide with
     *     another variant/product (and the unique-constraint write
     *     succeeds)                                                → else skip `barcode_conflict`.
     *   - Any other unexpected failure for a single item           → else skip `error`.
     *   - The effective price is resolved via PricingService.
     *
     * Best-effort partial assignment is INTENTIONAL: there is NO surrounding
     * transaction. Each item is isolated in its own try/catch so a single bad
     * item degrades to a `skipped` entry rather than 500ing the whole batch;
     * lazily-assigned barcodes for earlier items stay committed.
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

            try {
                $variant = ProductVariant::query()
                    ->where('tenant_id', $company->tenant_id)
                    ->where('company_id', $company->id)
                    ->find($variantId);

                if ($variant === null) {
                    $skipped[] = ['variant_id' => $variantId, 'reason' => 'not_found'];

                    continue;
                }

                // The variant row can outlive a soft-deleted parent product. Fetch
                // it nullable + tenant-scoped (a trashed product yields null) and
                // guard before dereferencing ->name. product_id is immutable across
                // a barcode-only update, so this $product stays valid below.
                $product = Product::query()
                    ->where('tenant_id', $company->tenant_id)
                    ->find($variant->product_id);

                if ($product === null) {
                    $skipped[] = ['variant_id' => $variantId, 'reason' => 'product_unavailable'];

                    continue;
                }

                if ($variant->barcode === null || $variant->barcode === '') {
                    if (! $this->valueIsUsable($company->tenant_id, $variant->sku, $variant->id)) {
                        $skipped[] = ['variant_id' => $variantId, 'reason' => 'barcode_conflict'];

                        continue;
                    }

                    try {
                        $variant = $this->variantService->updateVariant($variant, ['barcode' => $variant->sku]);
                    } catch (ValidationException $e) {
                        // Only a barcode-keyed validation failure is a real
                        // collision; anything else bubbles to the per-item
                        // \Throwable catch below as reason `error`.
                        if (! array_key_exists('barcode', $e->errors())) {
                            throw $e;
                        }

                        $skipped[] = ['variant_id' => $variantId, 'reason' => 'barcode_conflict'];

                        continue;
                    }
                } elseif ($this->collidesWithProductCode($company->tenant_id, (string) $variant->barcode)) {
                    // A PRE-EXISTING barcode that resolves to a product code (Tier 1)
                    // would mis-scan to that product — never print it.
                    $skipped[] = ['variant_id' => $variantId, 'reason' => 'barcode_conflict'];

                    continue;
                }

                $barcodeValue = (string) $variant->barcode;

                if (! $this->renderer->canEncode($barcodeValue)) {
                    // A non-ASCII (un-encodable) value would yield a corrupt,
                    // unscannable Code128 symbol — skip rather than print garbage.
                    $skipped[] = ['variant_id' => $variantId, 'reason' => 'unencodable_barcode'];

                    continue;
                }

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
                    product_name: $product->name,
                    name_suffix: $variant->name_suffix,
                    effective_price: $price,
                    barcode_value: $barcodeValue,
                    symbology: $this->renderer->symbologyFor($barcodeValue),
                    quantity: $quantity,
                    sku: $variant->sku,
                );
            } catch (\Throwable) {
                // Best-effort: a single failing item never 500s the batch.
                $skipped[] = ['variant_id' => $variantId, 'reason' => 'error'];

                continue;
            }
        }

        return ['ready' => $ready, 'skipped' => $skipped];
    }
}
