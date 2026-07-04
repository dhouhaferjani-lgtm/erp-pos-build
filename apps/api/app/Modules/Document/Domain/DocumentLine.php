<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $document_id
 * @property string|null $location_id
 * @property string|null $product_id
 * @property string|null $variant_id
 * @property int|null $batch_id
 * @property string|null $product_code
 * @property string|null $service_id
 * @property int $line_number
 * @property string $description
 * @property numeric-string $quantity
 * @property numeric-string $free_quantity
 * @property numeric-string $quantity_delivered
 * @property numeric-string $quantity_received
 * @property numeric-string $free_quantity_received
 * @property numeric-string $quantity_invoiced
 * @property numeric-string $free_quantity_invoiced
 * @property string $price_entry_mode
 * @property bool $is_bonus_line
 * @property numeric-string $unit_price
 * @property numeric-string|null $discount_percent
 * @property numeric-string|null $discount_amount
 * @property numeric-string|null $tax_rate
 * @property numeric-string|null $tax_amount Calculated line tax (recoverable + non-recoverable), scale 3
 * @property bool $tax_recoverable Whether this line's input VAT is recoverable
 * @property numeric-string|null $recoverable_tax_amount Recoverable input VAT portion, scale 3
 * @property numeric-string|null $non_recoverable_tax_amount Non-recoverable VAT capitalized into inventory cost, scale 3
 * @property numeric-string $line_total
 * @property numeric-string $allocated_costs
 * @property numeric-string|null $landed_unit_cost
 * @property numeric-string|null $accrual_unit_cost Receipt-time 408 accrual basis (immutable after receipt); null for pre-B3 rows.
 * @property numeric-string|null $price_match_basis Supplier invoice creation-time match basis snapshot.
 * @property string|null $matched_receipt_line_id First FIFO receipt-line slice used for the match snapshot.
 * @property numeric-string|null $non_recoverable_tax
 * @property string|null $notes
 * @property string|null $designation_default_snapshot
 * @property numeric-string|null $eco_tax_amount Eco-contribution amount (Phase 2)
 * @property numeric-string|null $eco_tax_rate Eco-tax rate as decimal fraction (Phase 2)
 * @property string|null $eco_tax_category Eco-tax category code, max 64 chars (Phase 2)
 * @property string|null $source_line_id
 * @property string|null $work_order_line_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Document $document
 * @property-read Location|null $location
 * @property-read Product|null $product
 * @property-read Batch|null $batch
 * @property-read Service|null $service
 * @property-read DocumentLine|null $sourceLine
 */
class DocumentLine extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'document_lines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'location_id',
        'product_id',
        'variant_id',
        'batch_id',
        'product_code',
        'service_id',
        'line_number',
        'description',
        'quantity',
        'free_quantity',
        'quantity_delivered',
        'quantity_received',
        'free_quantity_received',
        'quantity_invoiced',
        'free_quantity_invoiced',
        'price_entry_mode',
        'is_bonus_line',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'tax_recoverable',
        'recoverable_tax_amount',
        'non_recoverable_tax_amount',
        'line_total',
        'allocated_costs',
        'landed_unit_cost',
        'accrual_unit_cost',
        'price_match_basis',
        'matched_receipt_line_id',
        'notes',
        'designation_default_snapshot',
        'source_line_id',
        // Bare nullable UUID — back-reference to Workshop\WorkOrder\Domain\WorkOrderLine.
        // NOT FK-constrained per Spec §5.1 (cross-module loose reference).
        // Required in $fillable so DocumentGenerationAdapter mass-assignment
        // and CopiesDocumentData::copyLine() can both persist the column —
        // without it, default mass-assignment guarding silently drops it
        // (H2 audit finding).
        'work_order_line_id',
        // Phase 2 eco-tax forward compatibility (Phase 1: columns exist, Phase 2: writer wired)
        'eco_tax_amount',
        'eco_tax_rate',
        'eco_tax_category',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'free_quantity' => 'decimal:4',
            'quantity_delivered' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'free_quantity_received' => 'decimal:4',
            'quantity_invoiced' => 'decimal:4',
            'free_quantity_invoiced' => 'decimal:4',
            'is_bonus_line' => 'boolean',
            'unit_price' => 'decimal:3',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            // Tax recoverability columns widened to scale 3 by 2026_03_23_100000.
            'tax_amount' => 'decimal:3',
            'tax_recoverable' => 'boolean',
            'recoverable_tax_amount' => 'decimal:3',
            'non_recoverable_tax_amount' => 'decimal:3',
            'line_total' => 'decimal:3',
            // WAC-feeding cost columns carried at higher internal precision (6 dp)
            // at rest so the landed unit cost flowing into recordPurchase() is not
            // pre-truncated. See the scale-6 widening migration.
            'allocated_costs' => 'decimal:6',
            'landed_unit_cost' => 'decimal:6',
            'accrual_unit_cost' => 'decimal:6',
            'price_match_basis' => 'decimal:6',
            'non_recoverable_tax' => 'decimal:3',
            // Eco-tax columns: cast as strings for bcmath-safe arithmetic (project convention).
            // Phase 1: always null; Phase 2 wires the writer.
            'eco_tax_amount' => 'decimal:5',
            'eco_tax_rate' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the location this line ships from/receives to.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the source line this delivery line was created from.
     *
     * @return BelongsTo<DocumentLine, $this>
     */
    public function sourceLine(): BelongsTo
    {
        return $this->belongsTo(DocumentLine::class, 'source_line_id');
    }

    /**
     * Resolve effective location for this line.
     *
     * Falls back to parent document location if line doesn't specify.
     * This enables:
     * - Per-line location for multi-location orders
     * - Document-level location for simple scenarios
     * - Null for central invoicing (no location)
     */
    public function getEffectiveLocationId(): ?string
    {
        if ($this->location_id !== null) {
            return $this->location_id;
        }

        // Load document if not loaded
        if (! $this->relationLoaded('document')) {
            $this->load('document');
        }

        return $this->document->location_id;
    }

    /**
     * Calculate the line total (NET of any line discount, before tax).
     *
     * @return numeric-string
     */
    public function calculateTotal(int $scale = 3): string
    {
        return self::computeLineTotal(
            $this->quantity,
            $this->unit_price,
            $this->discount_percent,
            $this->discount_amount,
            $scale,
        );
    }

    /**
     * Canonical NET line total from raw values: gross (qty × unit_price) minus
     * the line discount (percent if present, otherwise the flat amount), before
     * tax. This is the single source of truth for line-level discount
     * arithmetic — used both by the {@see calculateTotal()} instance method and
     * by the document line builders that compute totals before a model exists.
     *
     * @param  numeric-string  $quantity
     * @param  numeric-string  $unitPrice
     * @param  numeric-string|null  $discountPercent
     * @param  numeric-string|null  $discountAmount
     * @return numeric-string
     */
    public static function computeLineTotal(
        string $quantity,
        string $unitPrice,
        ?string $discountPercent,
        ?string $discountAmount,
        int $scale = 3,
    ): string {
        $subtotal = bcmul($quantity, $unitPrice, $scale);

        // Discount before tax: percentage takes precedence over a flat amount.
        if ($discountPercent !== null && bccomp($discountPercent, '0', 4) !== 0) {
            $discount = bcmul($subtotal, bcdiv($discountPercent, '100', 4), $scale);
            $subtotal = bcsub($subtotal, $discount, $scale);
        } elseif ($discountAmount !== null && bccomp($discountAmount, '0', $scale) !== 0) {
            $subtotal = bcsub($subtotal, $discountAmount, $scale);
        }

        return $subtotal;
    }

    /**
     * Get the remaining quantity that has not yet been delivered.
     *
     * Only applicable to sales order lines.
     */
    public function getQuantityRemaining(): string
    {
        $delivered = $this->quantity_delivered ?? '0.00';

        return bcsub($this->quantity, $delivered, 4);
    }

    /**
     * Check if this line has been fully delivered.
     */
    public function isFullyDelivered(): bool
    {
        /** @var numeric-string $remaining */
        $remaining = $this->getQuantityRemaining();

        return bccomp($remaining, '0.00', 4) <= 0;
    }

    /**
     * Check if this line has any deliveries.
     */
    public function hasDeliveries(): bool
    {
        $delivered = (string) ($this->quantity_delivered ?? '0.0000');

        return bccomp($delivered, '0.0000', 4) > 0;
    }
}
