<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use App\Modules\Billing\Domain\Enums\InvoiceStatus;
use App\Modules\Billing\Domain\ValueObjects\Money;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Billing invoice for tenant subscription payments.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $subscription_id
 * @property string $number
 * @property InvoiceStatus $status
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $discount_amount
 * @property string $total
 * @property string $amount_paid
 * @property string $amount_due
 * @property string $currency
 * @property string $tax_rate
 * @property string|null $tax_number
 * @property array $billing_address
 * @property string|null $billing_email
 * @property string|null $billing_name
 * @property \Carbon\Carbon $invoice_date
 * @property \Carbon\Carbon $due_date
 * @property \Carbon\Carbon|null $paid_at
 * @property \Carbon\Carbon|null $sent_at
 * @property \Carbon\Carbon|null $period_start
 * @property \Carbon\Carbon|null $period_end
 * @property string|null $pdf_path
 * @property string|null $notes
 * @property string|null $footer_text
 * @property string|null $stripe_invoice_id
 * @property array $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class Invoice extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'billing_invoices';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'number',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'amount_paid',
        'amount_due',
        'currency',
        'tax_rate',
        'tax_number',
        'billing_address',
        'billing_email',
        'billing_name',
        'invoice_date',
        'due_date',
        'paid_at',
        'sent_at',
        'period_start',
        'period_end',
        'pdf_path',
        'notes',
        'footer_text',
        'stripe_invoice_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'billing_address' => 'array',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'sent_at' => 'datetime',
            'period_start' => 'date',
            'period_end' => 'date',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, Invoice>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<TenantSubscription, Invoice>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'subscription_id');
    }

    /**
     * @return HasMany<InvoiceItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<Payment>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get total as Money value object.
     */
    public function getTotalMoney(): Money
    {
        return new Money((float) $this->total, $this->currency);
    }

    /**
     * Get amount due as Money value object.
     */
    public function getAmountDueMoney(): Money
    {
        return new Money((float) $this->amount_due, $this->currency);
    }

    /**
     * Check if invoice is fully paid.
     */
    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    /**
     * Check if invoice is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->due_date->isPast() && $this->status->isPayable();
    }

    /**
     * Mark invoice as sent.
     */
    public function markAsSent(): void
    {
        $this->update([
            'status' => InvoiceStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    /**
     * Record a payment against this invoice.
     */
    public function recordPayment(float $amount): void
    {
        $newAmountPaid = (float) $this->amount_paid + $amount;
        $newAmountDue = (float) $this->total - $newAmountPaid;

        $status = $newAmountDue <= 0
            ? InvoiceStatus::Paid
            : InvoiceStatus::PartiallyPaid;

        $this->update([
            'amount_paid' => $newAmountPaid,
            'amount_due' => max(0, $newAmountDue),
            'status' => $status,
            'paid_at' => $status === InvoiceStatus::Paid ? now() : null,
        ]);
    }

    /**
     * Calculate totals from items.
     */
    public function recalculateTotals(): void
    {
        $subtotal = $this->items()->sum('amount');
        $taxAmount = $this->items()->sum('tax_amount');
        $discountAmount = $this->items()->sum('discount_amount');
        $total = $subtotal + $taxAmount - $discountAmount;

        $this->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total' => $total,
            'amount_due' => $total - (float) $this->amount_paid,
        ]);
    }
}
