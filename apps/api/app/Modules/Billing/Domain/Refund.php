<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use App\Models\SuperAdmin;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\ValueObjects\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Refund record for a payment.
 *
 * @property string $id
 * @property string $payment_id
 * @property string|null $provider_refund_id
 * @property PaymentStatus $status
 * @property string $amount
 * @property string $currency
 * @property string|null $reason
 * @property string|null $notes
 * @property string|null $initiated_by
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $refunded_at
 * @property array<string, mixed> $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Refund extends Model
{
    // Central table — platform billing (invoices the platform issues to tenants).
    use CentralConnection;
    use HasUuids;

    protected $table = 'billing_refunds';

    protected $fillable = [
        'payment_id',
        'provider_refund_id',
        'status',
        'amount',
        'currency',
        'reason',
        'notes',
        'initiated_by',
        'error_code',
        'error_message',
        'refunded_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:3',
            'refunded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<SuperAdmin, $this>
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'initiated_by');
    }

    /**
     * Get amount as Money value object.
     */
    public function getAmountMoney(): Money
    {
        return new Money((string) $this->amount, $this->currency);
    }

    /**
     * Mark refund as succeeded.
     */
    public function markAsSucceeded(): void
    {
        $this->update([
            'status' => PaymentStatus::Succeeded,
            'refunded_at' => now(),
        ]);

        // Update parent payment's refunded amount
        /** @var Payment $payment */
        $payment = $this->payment;
        $payment->recordRefund((float) $this->amount);
    }

    /**
     * Mark refund as failed.
     */
    public function markAsFailed(string $errorMessage, ?string $errorCode = null): void
    {
        $this->update([
            'status' => PaymentStatus::Failed,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
        ]);
    }
}
