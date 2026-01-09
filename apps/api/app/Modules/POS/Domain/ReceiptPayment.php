<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS Receipt Payment Entity
 *
 * Represents a single payment method used on a receipt.
 * Supports split payments (multiple payment methods per receipt).
 * Required for NF525 compliance - feeds into payment_methods_hash.
 *
 * @property string $id
 * @property string $receipt_id
 * @property string $payment_method_id
 * @property string $payment_type Immutable snapshot of payment type
 * @property numeric-string $amount Amount paid with this method
 * @property string|null $voucher_serial Restaurant voucher serial (if applicable)
 * @property string|null $card_last_four Last 4 digits of card (if card payment)
 * @property string|null $transaction_reference External transaction reference
 * @property string|null $authorization_code Card authorization code
 * @property Carbon|null $authorized_at Authorization timestamp
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Receipt $receipt
 * @property-read PaymentMethod $paymentMethod
 */
class ReceiptPayment extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_receipt_payments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'receipt_id',
        'payment_method_id',
        'payment_type',
        'amount',
        'voucher_serial',
        'card_last_four',
        'transaction_reference',
        'authorization_code',
        'authorized_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'authorized_at' => 'datetime',
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
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * Check if payment was made with voucher
     */
    public function isVoucher(): bool
    {
        return $this->voucher_serial !== null;
    }

    /**
     * Check if payment was made with card
     */
    public function isCard(): bool
    {
        return $this->card_last_four !== null;
    }

    /**
     * Check if payment is authorized
     */
    public function isAuthorized(): bool
    {
        return $this->authorization_code !== null && $this->authorized_at !== null;
    }

    /**
     * Get masked card number for display
     */
    public function getMaskedCardNumber(): ?string
    {
        if (! $this->isCard()) {
            return null;
        }

        return "****{$this->card_last_four}";
    }

    /**
     * Get formatted payment detail line for receipt printing
     */
    public function getDisplayLine(): string
    {
        $line = "{$this->payment_type}: {$this->amount}";

        if ($this->isVoucher()) {
            $line .= " (Voucher: {$this->voucher_serial})";
        } elseif ($this->isCard()) {
            $line .= " (Card: {$this->getMaskedCardNumber()})";
        }

        return $line;
    }
}
