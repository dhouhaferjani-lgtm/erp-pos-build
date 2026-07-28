<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use App\Shared\Domain\CurrencyScale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Line item on a billing invoice.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $description
 * @property string|null $long_description
 * @property string $quantity
 * @property-read int $quantity_decimals
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
    // Central table — platform billing (invoices the platform issues to tenants).
    use CentralConnection;
    use HasUuids;

    protected $table = 'billing_invoice_items';

    /** @var list<string> */
    protected $appends = ['quantity_decimals'];

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
     * Billing invoice quantities are stored at the central table's fixed scale.
     */
    public function getQuantityDecimalsAttribute(): int
    {
        return 2;
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
     *
     * Returns a numeric-string at intermediate scale (EUR scale 2 + 1 = 3).
     * Billing invoices are always in EUR; scale is resolved from the ISO map.
     *
     * @return numeric-string
     */
    public function calculateAmount(): string
    {
        $interScale = CurrencyScale::for('EUR') + 1; // 3
        /** @var numeric-string $qty */
        $qty = (string) $this->quantity;
        /** @var numeric-string $price */
        $price = (string) $this->unit_price;

        return bcmul($qty, $price, $interScale);
    }

    /**
     * Calculate tax amount.
     *
     * tax_rate is a RATE (not currency-scaled); divide by 100 at extra precision
     * before multiplying to avoid scale loss.
     */
    public function calculateTaxAmount(): string
    {
        $interScale = CurrencyScale::for('EUR') + 1; // 3
        /** @var numeric-string $taxRate */
        $taxRate = (string) $this->tax_rate;
        $rateFraction = bcdiv($taxRate, '100', $interScale + 3);

        return bcmul($this->calculateAmount(), $rateFraction, $interScale);
    }

    /**
     * Calculate discount amount.
     *
     * discount_percent is a RATE (not currency-scaled); same pattern as tax.
     */
    public function calculateDiscountAmount(): string
    {
        $interScale = CurrencyScale::for('EUR') + 1; // 3
        /** @var numeric-string $discountPercent */
        $discountPercent = (string) $this->discount_percent;
        $discountFraction = bcdiv($discountPercent, '100', $interScale + 3);

        return bcmul($this->calculateAmount(), $discountFraction, $interScale);
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
