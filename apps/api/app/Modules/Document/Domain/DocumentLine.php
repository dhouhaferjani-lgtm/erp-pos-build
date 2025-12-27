<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain;

use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $document_id
 * @property string|null $product_id
 * @property string|null $product_code
 * @property string|null $service_id
 * @property int $line_number
 * @property string $description
 * @property numeric-string $quantity
 * @property numeric-string $quantity_delivered
 * @property numeric-string $quantity_received
 * @property numeric-string $unit_price
 * @property numeric-string|null $discount_percent
 * @property numeric-string|null $discount_amount
 * @property numeric-string|null $tax_rate
 * @property numeric-string $line_total
 * @property numeric-string $allocated_costs
 * @property numeric-string|null $landed_unit_cost
 * @property string|null $notes
 * @property string|null $source_line_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Document $document
 * @property-read Product|null $product
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
        'product_id',
        'product_code',
        'service_id',
        'line_number',
        'description',
        'quantity',
        'quantity_delivered',
        'quantity_received',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'tax_rate',
        'line_total',
        'allocated_costs',
        'landed_unit_cost',
        'notes',
        'source_line_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'quantity_delivered' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'allocated_costs' => 'decimal:2',
            'landed_unit_cost' => 'decimal:2',
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
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
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
     * Calculate the line total
     */
    public function calculateTotal(): string
    {
        $subtotal = bcmul($this->quantity, $this->unit_price, 2);

        // Apply discount if any
        if ($this->discount_percent !== null && $this->discount_percent !== '0.00') {
            $discount = bcmul($subtotal, bcdiv($this->discount_percent, '100', 4), 2);
            $subtotal = bcsub($subtotal, $discount, 2);
        } elseif ($this->discount_amount !== null && $this->discount_amount !== '0.00') {
            $subtotal = bcsub($subtotal, $this->discount_amount, 2);
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
