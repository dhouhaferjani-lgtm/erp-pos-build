<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

/**
 * One row in `fiscal_events.payload.line_items[]` — 13 properties per
 * Candidate C-v3 §3, plus the SaleReceiptV2 (M4, event_version=2) variant
 * identity (`variant_id` / `variant_name` / `variant_sku`). The variant
 * fields are absent on v1 payloads and null for non-variant v2 lines, so
 * this single DTO parses both versions: v1 rows hydrate to nulls.
 *
 * Money fields (`unit_price`, `line_subtotal`, `line_vat`,
 * `line_discount_amount`) are bcformat strings at currency_scale.
 * `quantity` is bcformat at fixed quantity_scale=3 (Phase 1).
 * `vat_rate` is bcformat at vat_rate_scale=2 (e.g. "20.00", "5.50").
 *
 * `tax_category_code` empty string means standard taxable; non-empty
 * uses the unified KSA BT-151 / IT Natura axis.
 * `non_collected_subtype` is IT-future (`servizi|beni|omaggio|successiva`).
 * `gtin` is DSFinV-K-future.
 *
 * `name`/`sku`/`product_id` remain the PARENT product identity in both
 * versions; `unit_price` is the variant price when a variant was sold.
 */
final readonly class LineItemDTO
{
    public function __construct(
        public ?string $gtin,
        public string $lineDiscountAmount,
        public ?string $lineDiscountReason,
        public string $lineSubtotal,
        public string $lineVat,
        public string $name,
        public ?string $nonCollectedSubtype,
        public string $productId,
        public string $quantity,
        public string $sku,
        public string $taxCategoryCode,
        public string $unitPrice,
        public string $vatRate,
        public ?string $variantId = null,
        public ?string $variantName = null,
        public ?string $variantSku = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            gtin: FiscalPayloadArrayGuards::optionalString($data, 'gtin'),
            lineDiscountAmount: FiscalPayloadArrayGuards::requireString($data, 'line_discount_amount'),
            lineDiscountReason: FiscalPayloadArrayGuards::optionalString($data, 'line_discount_reason'),
            lineSubtotal: FiscalPayloadArrayGuards::requireString($data, 'line_subtotal'),
            lineVat: FiscalPayloadArrayGuards::requireString($data, 'line_vat'),
            name: FiscalPayloadArrayGuards::requireString($data, 'name'),
            nonCollectedSubtype: FiscalPayloadArrayGuards::optionalString($data, 'non_collected_subtype'),
            productId: FiscalPayloadArrayGuards::requireString($data, 'product_id'),
            quantity: FiscalPayloadArrayGuards::requireString($data, 'quantity'),
            sku: FiscalPayloadArrayGuards::requireString($data, 'sku'),
            taxCategoryCode: FiscalPayloadArrayGuards::requireString($data, 'tax_category_code'),
            unitPrice: FiscalPayloadArrayGuards::requireString($data, 'unit_price'),
            vatRate: FiscalPayloadArrayGuards::requireString($data, 'vat_rate'),
            // V2 variant identity — absent on v1 payloads, null when the
            // line is not a variant sale.
            variantId: array_key_exists('variant_id', $data)
                ? FiscalPayloadArrayGuards::optionalString($data, 'variant_id')
                : null,
            variantName: array_key_exists('variant_name', $data)
                ? FiscalPayloadArrayGuards::optionalString($data, 'variant_name')
                : null,
            variantSku: array_key_exists('variant_sku', $data)
                ? FiscalPayloadArrayGuards::optionalString($data, 'variant_sku')
                : null,
        );
    }
}
