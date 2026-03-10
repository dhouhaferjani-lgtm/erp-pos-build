<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use App\Models\SuperAdmin;
use App\Modules\Billing\Domain\Enums\PaymentProviderCode;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\ValueObjects\Money;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Payment record for billing.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $invoice_id
 * @property string $provider
 * @property string|null $provider_payment_id
 * @property PaymentStatus $status
 * @property string $amount
 * @property string $fee
 * @property string $net_amount
 * @property string $currency
 * @property string $refunded_amount
 * @property string|null $payment_method_type
 * @property array<string, mixed> $payment_method_details
 * @property string|null $reference_number
 * @property \Carbon\Carbon|null $payment_date
 * @property string|null $recorded_by
 * @property string|null $client_secret
 * @property string|null $action_url
 * @property string|null $error_code
 * @property string|null $error_message
 * @property \Carbon\Carbon|null $paid_at
 * @property \Carbon\Carbon|null $refunded_at
 * @property array<string, mixed> $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class Payment extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'billing_payments';

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'provider',
        'provider_payment_id',
        'status',
        'amount',
        'fee',
        'net_amount',
        'currency',
        'refunded_amount',
        'payment_method_type',
        'payment_method_details',
        'reference_number',
        'payment_date',
        'recorded_by',
        'client_secret',
        'action_url',
        'error_code',
        'error_message',
        'paid_at',
        'refunded_at',
        'metadata',
    ];

    protected $hidden = [
        'client_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:3',
            'fee' => 'decimal:3',
            'net_amount' => 'decimal:3',
            'refunded_amount' => 'decimal:3',
            'payment_method_details' => 'array',
            'payment_date' => 'date',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
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
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<SuperAdmin, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'recorded_by');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Get the payment provider enum.
     */
    public function getProviderCode(): PaymentProviderCode
    {
        return PaymentProviderCode::from($this->provider);
    }

    /**
     * Get amount as Money value object.
     */
    public function getAmountMoney(): Money
    {
        return new Money((float) $this->amount, $this->currency);
    }

    /**
     * Get net amount as Money value object.
     */
    public function getNetAmountMoney(): Money
    {
        return new Money((float) $this->net_amount, $this->currency);
    }

    /**
     * Check if payment was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }

    /**
     * Check if payment requires user action.
     */
    public function requiresAction(): bool
    {
        return $this->status === PaymentStatus::RequiresAction;
    }

    /**
     * Check if payment is refundable.
     */
    public function isRefundable(): bool
    {
        if (! $this->isSuccessful()) {
            return false;
        }

        $refundableAmount = (float) $this->amount - (float) $this->refunded_amount;

        return $refundableAmount > 0;
    }

    /**
     * Get remaining refundable amount.
     */
    public function getRefundableAmount(): float
    {
        return max(0, (float) $this->amount - (float) $this->refunded_amount);
    }

    /**
     * Mark payment as succeeded.
     */
    public function markAsSucceeded(): void
    {
        $this->update([
            'status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);

        // Update invoice if linked
        if ($this->invoice_id && $this->invoice !== null) {
            $this->invoice->recordPayment((float) $this->amount);
        }
    }

    /**
     * Mark payment as failed.
     */
    public function markAsFailed(string $errorMessage, ?string $errorCode = null): void
    {
        $this->update([
            'status' => PaymentStatus::Failed,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
        ]);
    }

    /**
     * Record a refund.
     */
    public function recordRefund(float $amount): void
    {
        $newRefundedAmount = (float) $this->refunded_amount + $amount;
        $isFullRefund = $newRefundedAmount >= (float) $this->amount;

        $this->update([
            'refunded_amount' => $newRefundedAmount,
            'status' => $isFullRefund
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded,
            'refunded_at' => $isFullRefund ? now() : $this->refunded_at,
        ]);
    }

    /**
     * Check if this is a manual payment.
     */
    public function isManual(): bool
    {
        return in_array($this->provider, [
            PaymentProviderCode::Manual->value,
            PaymentProviderCode::BankTransfer->value,
            PaymentProviderCode::Cash->value,
            PaymentProviderCode::Check->value,
        ], true);
    }
}
