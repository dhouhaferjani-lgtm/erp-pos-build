<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use App\Modules\Document\Domain\DocumentLine;
use App\Shared\Domain\CurrencyScale;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DocumentLineData extends Data
{
    public function __construct(
        public string $id,
        public string $document_id,
        public ?string $product_id,
        public int $line_number,
        public string $description,
        public string $quantity,
        public string $free_quantity,
        public string $unit_price,
        public ?string $discount_percent,
        public ?string $discount_amount,
        public ?string $tax_rate,
        public string $line_total,
        public string $price_entry_mode,
        public bool $is_bonus_line,
        public ?string $notes,
        public ?string $designation_default_snapshot,
        public int $quantity_decimals,
        public bool $requires_batch_tracking,
    ) {}

    public static function fromModel(DocumentLine $line, int $scale = 3): self
    {
        return new self(
            id: $line->id,
            document_id: $line->document_id,
            product_id: $line->product_id,
            line_number: $line->line_number,
            description: $line->description,
            quantity: CurrencyScale::bcformat($line->quantity, $scale),
            free_quantity: CurrencyScale::bcformat($line->free_quantity ?? '0', 4),
            unit_price: CurrencyScale::bcformat($line->unit_price, $scale),
            discount_percent: $line->discount_percent !== null ? CurrencyScale::bcformat($line->discount_percent, 2) : null,
            discount_amount: $line->discount_amount !== null ? CurrencyScale::bcformat($line->discount_amount, $scale) : null,
            tax_rate: $line->tax_rate !== null ? CurrencyScale::bcformat($line->tax_rate, 2) : null,
            line_total: CurrencyScale::bcformat($line->line_total, $scale),
            price_entry_mode: (string) ($line->price_entry_mode ?? 'unit'),
            is_bonus_line: (bool) ($line->is_bonus_line ?? false),
            notes: $line->notes,
            designation_default_snapshot: $line->designation_default_snapshot,
            quantity_decimals: self::resolveQuantityDecimals($line),
            requires_batch_tracking: self::requiresBatchTracking($line),
        );
    }

    /**
     * Derive the line's quantity precision from its product's unit of measure.
     * Falls back to the canonical storage scale (4) when the relation chain is
     * not eager-loaded — callers building a response MUST eager-load
     * `lines.product.unitOfMeasure` to surface the real per-unit precision.
     */
    private static function resolveQuantityDecimals(DocumentLine $line): int
    {
        if ($line->relationLoaded('product')
            && $line->product !== null
            && $line->product->relationLoaded('unitOfMeasure')
            && $line->product->unitOfMeasure !== null) {
            return $line->product->unitOfMeasure->decimal_places;
        }

        return 4;
    }

    private static function requiresBatchTracking(DocumentLine $line): bool
    {
        if ($line->product_id === null) {
            return false;
        }

        if (! $line->relationLoaded('product')) {
            $line->load('product');
        }

        return $line->relationLoaded('product')
            && $line->product !== null
            && $line->product->requires_batch_tracking === true;
    }
}
