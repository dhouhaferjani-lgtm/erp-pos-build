<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use App\Modules\Billing\Domain\Enums\InvoiceStatus;
use App\Modules\Billing\Domain\ValueObjects\Money;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\CurrencyScale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

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
 * @property array<string, mixed> $billing_address
 * @property string|null $billing_email
 * @property string|null $billing_name
 * @property Carbon $invoice_date
 * @property Carbon $due_date
 * @property Carbon|null $paid_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string|null $pdf_path
 * @property string|null $notes
 * @property string|null $footer_text
 * @property string|null $stripe_invoice_id
 * @property array<string, mixed> $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Invoice extends Model
{
    // Central table — platform billing (invoices the platform issues to tenants).
    use CentralConnection;
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
            'subtotal' => 'decimal:3',
            'tax_amount' => 'decimal:3',
            'discount_amount' => 'decimal:3',
            'total' => 'decimal:3',
            'amount_paid' => 'decimal:3',
            'amount_due' => 'decimal:3',
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
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<TenantSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'subscription_id');
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<Payment, $this>
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
        return new Money((string) $this->total, $this->currency);
    }

    /**
     * Get amount due as Money value object.
     */
    public function getAmountDueMoney(): Money
    {
        return new Money((string) $this->amount_due, $this->currency);
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
     *
     * @param  numeric-string  $amount
     */
    public function recordPayment(string $amount): void
    {
        $scale = CurrencyScale::for($this->currency);
        /** @var numeric-string $amountPaid */
        $amountPaid = (string) $this->amount_paid;
        /** @var numeric-string $total */
        $total = (string) $this->total;
        $newAmountPaid = bcadd($amountPaid, $amount, $scale);
        $newAmountDue = bcsub($total, $newAmountPaid, $scale);

        // bccomp — never float comparison; bccomp(due,'0',scale)<=0 means fully paid
        $isPaid = bccomp($newAmountDue, '0', $scale) <= 0;
        $status = $isPaid ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;
        // Clamp: if overpaid by rounding, floor to '0.00' at currency scale
        $clampedDue = $isPaid ? bcadd('0', '0', $scale) : $newAmountDue;

        $this->update([
            'amount_paid' => $newAmountPaid,
            'amount_due' => $clampedDue,
            'status' => $status,
            'paid_at' => $isPaid ? now() : null,
        ]);
    }

    /**
     * Calculate totals from items using bcmath — no float on money paths.
     *
     * Accumulates item amounts via bcadd at the invoice currency scale so that
     * amount_due is always computed by the same bcmath gate that recordPayment uses
     * (bccomp-safe). SQL Builder::sum() returns a PHP float; bcadd over the loaded
     * items avoids that float conversion entirely.
     */
    public function recalculateTotals(): void
    {
        $scale = CurrencyScale::for($this->currency);
        $zero = CurrencyScale::bcformat('0', $scale);

        $items = $this->items()->get();

        $subtotal = $zero;
        $taxAmount = $zero;
        $discountAmount = $zero;

        foreach ($items as $item) {
            /** @var numeric-string $itemAmount */
            $itemAmount = (string) $item->amount;
            /** @var numeric-string $itemTax */
            $itemTax = (string) $item->tax_amount;
            /** @var numeric-string $itemDiscount */
            $itemDiscount = (string) $item->discount_amount;

            $subtotal = bcadd($subtotal, $itemAmount, $scale);
            $taxAmount = bcadd($taxAmount, $itemTax, $scale);
            $discountAmount = bcadd($discountAmount, $itemDiscount, $scale);
        }

        $total = bcsub(bcadd($subtotal, $taxAmount, $scale), $discountAmount, $scale);
        /** @var numeric-string $amountPaid */
        $amountPaid = (string) $this->amount_paid;
        $amountDue = bcsub($total, $amountPaid, $scale);

        $this->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total' => $total,
            'amount_due' => $amountDue,
        ]);
    }
}
