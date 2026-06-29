<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\POS\Domain\Enums\ReturnLineDisposition;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS Receipt Line Entity
 *
 * Represents a single line item on a POS receipt.
 * Immutable after receipt creation (part of fiscal record).
 *
 * @property string $id
 * @property string $receipt_id
 * @property int $line_number
 * @property string|null $product_id
 * @property string|null $variant_id
 * @property string|null $composite_item_id
 * @property string|null $menu_category_id
 * @property string $product_code Product code at time of sale (immutable)
 * @property string $product_name Product name at time of sale (immutable)
 * @property string|null $original_line_id FK to original sale line (set on return lines)
 * @property string|null $product_description
 * @property numeric-string $quantity
 * @property bool|null $physical_receipt Whether the physical goods were actually returned
 * @property bool|null $resalable Whether the returned item is in resalable condition
 * @property ReturnLineDisposition|null $disposition Disposition decision for returned item
 * @property string $unit
 * @property numeric-string $unit_price
 * @property numeric-string|null $unit_cost
 * @property numeric-string $line_total
 * @property numeric-string $tax_rate
 * @property numeric-string $tax_amount
 * @property array<string, mixed>|null $modifiers JSONB: Future menu item modifiers
 * @property array<int, string>|null $combo_components JSONB: Component names for fixed_bundle combos
 * @property numeric-string $discount_amount
 * @property string|null $discount_reason
 * @property string|null $notes
 * @property numeric-string|null $eco_tax_amount Eco-contribution amount (Phase 2)
 * @property numeric-string|null $eco_tax_rate Eco-tax rate as decimal fraction (Phase 2)
 * @property string|null $eco_tax_category Eco-tax category code, max 64 chars (Phase 2)
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Receipt $receipt
 * @property-read ReceiptLine|null $originalLine
 * @property-read Product|null $product
 * @property-read CompositeItem|null $compositeItem
 */
class ReceiptLine extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_receipt_lines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'receipt_id',
        'original_line_id',
        'line_number',
        'product_id',
        'variant_id',
        'composite_item_id',
        // C2 Day 3 — menu_category_id surviving the wire roundtrip so the
        // refund flow can reconstruct the composite the cashier sold
        // under. Nullable; non-Menu tenants and pre-C2 historical rows
        // do not populate this column.
        'menu_category_id',
        'product_code',
        'product_name',
        'product_description',
        'quantity',
        'unit',
        'unit_price',
        'unit_cost',
        'line_total',
        'tax_rate',
        'tax_amount',
        'modifiers',
        'combo_components',
        'discount_amount',
        'discount_reason',
        'notes',
        // Phase 2 eco-tax forward compatibility (Phase 1: columns exist, Phase 2: writer wired)
        'eco_tax_amount',
        'eco_tax_rate',
        'eco_tax_category',
        // Return disposition fields (Phase 0 — columns only; writer wired in later task)
        'physical_receipt',
        'resalable',
        'disposition',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:3',
            'discount_amount' => 'decimal:3',
            'modifiers' => 'array',
            'combo_components' => 'array',
            // Eco-tax columns: cast as strings for bcmath-safe arithmetic (project convention).
            // Phase 1: always null; Phase 2 wires the writer.
            'eco_tax_amount' => 'decimal:5',
            'eco_tax_rate' => 'decimal:4',
            // Return disposition fields
            'physical_receipt' => 'boolean',
            'resalable' => 'boolean',
            'disposition' => ReturnLineDisposition::class,
        ];
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    /**
     * The original sale line this return line was created from.
     *
     * Only set on return receipt lines. Null for sale receipt lines.
     *
     * @return BelongsTo<ReceiptLine, $this>
     */
    public function originalLine(): BelongsTo
    {
        return $this->belongsTo(ReceiptLine::class, 'original_line_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<CompositeItem, $this>
     */
    public function compositeItem(): BelongsTo
    {
        return $this->belongsTo(CompositeItem::class, 'composite_item_id');
    }

    /**
     * Calculate net amount (before tax)
     */
    public function getNetAmount(int $scale = 3): string
    {
        return bcsub($this->line_total, $this->tax_amount, $scale);
    }

    /**
     * Check if line has modifiers
     */
    public function hasModifiers(): bool
    {
        return ! empty($this->modifiers);
    }

    /**
     * Check if line has discount
     */
    public function hasDiscount(int $scale = 3): bool
    {
        return bccomp($this->discount_amount, '0', $scale) > 0;
    }

    /**
     * Get formatted line display for receipt printing
     */
    public function getDisplayLine(): string
    {
        $line = "{$this->quantity} x {$this->product_name} @ {$this->unit_price}";

        if ($this->hasDiscount()) {
            $line .= " (-{$this->discount_amount})";
        }

        $line .= " = {$this->line_total}";

        return $line;
    }
}
