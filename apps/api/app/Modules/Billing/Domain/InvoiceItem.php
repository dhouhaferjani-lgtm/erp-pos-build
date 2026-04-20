<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Line item on a billing invoice.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $description
 * @property string|null $long_description
 * @property string $quantity
 * @property string $unit_price
 * @property string $amount
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $discount_percent
 * @property string $discount_amount
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property int $sort_order
 * @property array<string, mixed> $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class InvoiceItem extends Model
{
    use HasUuids;

    protected $table = 'billing_invoice_items';

    protected $fillable = [
        'invoice_id',
        'description',
        'long_description',
        'quantity',
        'unit_price',
        'amount',
        'tax_rate',
        'tax_amount',
        'discount_percent',
        'discount_amount',
        'period_start',
        'period_end',
        'reference_type',
        'reference_id',
        'sort_order',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:3',
            'amount' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:3',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:3',
            'period_start' => 'date',
            'period_end' => 'date',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Calculate amount based on quantity and unit price.
     */
    public function calculateAmount(): float
    {
        return (float) $this->quantity * (float) $this->unit_price;
    }

    /**
     * Calculate tax amount.
     */
    public function calculateTaxAmount(): float
    {
        return $this->calculateAmount() * ((float) $this->tax_rate / 100);
    }

    /**
     * Calculate discount amount.
     */
    public function calculateDiscountAmount(): float
    {
        return $this->calculateAmount() * ((float) $this->discount_percent / 100);
    }

    /**
     * Calculate and set all amounts.
     */
    public function calculateTotals(): void
    {
        $this->amount = (string) $this->calculateAmount();
        $this->tax_amount = (string) $this->calculateTaxAmount();
        $this->discount_amount = (string) $this->calculateDiscountAmount();
    }
}
