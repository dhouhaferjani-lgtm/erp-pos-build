<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS Order Line Entity
 *
 * Represents a single line item on a POS order.
 * Unlike receipt lines, order lines are mutable until the order is closed.
 *
 * @property string $id
 * @property string $order_id
 * @property int $line_number
 * @property string $product_id
 * @property string $product_name
 * @property string|null $variant_name
 * @property string|null $barcode
 * @property numeric-string $quantity
 * @property numeric-string $unit_price
 * @property numeric-string $discount_amount
 * @property numeric-string $tax_rate
 * @property numeric-string $tax_amount
 * @property numeric-string $line_total
 * @property array<string, mixed>|null $modifiers
 * @property string|null $special_instructions
 * @property OrderLineStatus $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $prepared_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 * @property-read Product $product
 */
class OrderLine extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_order_lines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'line_number',
        'product_id',
        'product_name',
        'variant_name',
        'barcode',
        'quantity',
        'unit_price',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'line_total',
        'modifiers',
        'special_instructions',
        'status',
        'sent_at',
        'prepared_at',
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
            'discount_amount' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:3',
            'line_total' => 'decimal:3',
            'modifiers' => 'array',
            'status' => OrderLineStatus::class,
            'sent_at' => 'datetime',
            'prepared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Check if line has modifiers.
     */
    public function hasModifiers(): bool
    {
        return ! empty($this->modifiers);
    }

    /**
     * Check if line has a discount.
     */
    public function hasDiscount(): bool
    {
        return bccomp($this->discount_amount, '0', 4) > 0;
    }
}
