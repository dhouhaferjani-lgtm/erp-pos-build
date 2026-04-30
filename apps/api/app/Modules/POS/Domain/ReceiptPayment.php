<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Factories\ReceiptPaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 * instrument_serial / instrument_type:
 *   Originally this model had a `voucher_serial` column whose sole meaning was
 *   "restaurant-voucher (ticket-restaurant) serial number". Task 21 (spec §3.3)
 *   renamed that column to `instrument_serial` and added `instrument_type` to
 *   discriminate between store_voucher, restaurant_voucher, gift_card, and none.
 *   Existing rows that had a non-null serial were backfilled with
 *   instrument_type = 'restaurant_voucher' to preserve the original meaning.
 *   New store-voucher rows written by VoucherIssuanceService use
 *   instrument_type = 'store_voucher'.
 *
 * @property string $id
 * @property string $receipt_id
 * @property string $payment_method_id
 * @property string $payment_type Immutable snapshot of payment type
 * @property string $payment_method_code Immutable snapshot of payment method code
 *                                       (sourced from payment_methods.code at receipt-creation time).
 *                                       Bound into the v3 canonical fiscal hash so a rename of the linked
 *                                       payment_methods row cannot retroactively change a sealed receipt's
 *                                       canonical input. See Codex review B2 (2026-04-30).
 * @property numeric-string $amount Amount paid with this method
 * @property string|null $instrument_serial Instrument serial (store voucher code, restaurant ticket serial, etc.)
 * @property PaymentInstrumentKind|null $instrument_type Discriminator for instrument_serial kind
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
    /** @use HasFactory<ReceiptPaymentFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_receipt_payments';

    protected static function newFactory(): ReceiptPaymentFactory
    {
        return ReceiptPaymentFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'receipt_id',
        'payment_method_id',
        'payment_type',
        'payment_method_code',
        'amount',
        'instrument_serial',
        'instrument_type',
        'card_last_four',
        'transaction_reference',
        'authorization_code',
        'authorized_at',
        'treasury_payment_id',
    ];

    /**
     * Defensive auto-snapshot for `payment_method_code`.
     *
     * Most application call sites (ReceiptPaymentService, ReceiptSyncService) now
     * snapshot `payment_methods.code` explicitly when creating a row. This boot hook
     * exists to keep older test fixtures and any future caller honest: if the row is
     * being inserted with a `payment_method_id` but no `payment_method_code`, fetch
     * the code from the linked PaymentMethod once at write time. The column itself
     * is NOT NULL, so a row with neither id nor code will still error out at the DB
     * level — exactly the contract the unit tests assert.
     *
     * Constructor injection rule (CLAUDE.md #13) does not apply here: Eloquent boot
     * hooks are static-class plumbing, and the lookup is a model-level concern, not
     * a service dependency. The cost of an extra SELECT per write is acceptable
     * because (a) tests dominate this code path and (b) the production callers
     * already supply the snapshot, so the lookup is a no-op there.
     */
    protected static function booted(): void
    {
        static::creating(function (ReceiptPayment $payment): void {
            // `payment_method_id` is a NOT NULL FK at the schema level, so we
            // only check whether the snapshot column was already populated by
            // the caller. `getAttribute()` (rather than the typed magic accessor)
            // is used here because PHPStan resolves the docblock-declared
            // string type for `$payment_method_code` and would treat a direct
            // null comparison as always-false.
            $existing = $payment->getAttribute('payment_method_code');
            if ($existing !== null) {
                return;
            }

            /** @var PaymentMethod|null $method */
            $method = PaymentMethod::query()->find($payment->payment_method_id);
            if ($method !== null) {
                $payment->payment_method_code = $method->code;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'authorized_at' => 'datetime',
            'instrument_type' => PaymentInstrumentKind::class,
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
     * Check if payment was made with any instrument (store voucher, restaurant voucher, gift card).
     *
     * Previously this checked `voucher_serial !== null`; after the Task 21 rename it checks
     * `instrument_serial !== null`. The method name is kept unchanged for backward compatibility.
     */
    public function isVoucher(): bool
    {
        return $this->instrument_serial !== null;
    }

    /**
     * Check if payment was made with a Phase 1 store-credit voucher specifically.
     */
    public function isStoreVoucher(): bool
    {
        return $this->instrument_type === PaymentInstrumentKind::StoreVoucher;
    }

    /**
     * Check if payment was made with a restaurant voucher (ticket-restaurant).
     * This is the legacy meaning of the old `voucher_serial` column.
     */
    public function isRestaurantVoucher(): bool
    {
        return $this->instrument_type === PaymentInstrumentKind::RestaurantVoucher;
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
     * Get formatted payment detail line for receipt printing.
     *
     * Uses instrument_serial (renamed from voucher_serial in Task 21).
     */
    public function getDisplayLine(): string
    {
        $line = "{$this->payment_type}: {$this->amount}";

        if ($this->isVoucher()) {
            $instrumentType = $this->instrument_type;
            $kindLabel = $instrumentType !== null ? $instrumentType->value : 'voucher';
            $line .= " ({$kindLabel}: {$this->instrument_serial})";
        } elseif ($this->isCard()) {
            $line .= " (Card: {$this->getMaskedCardNumber()})";
        }

        return $line;
    }
}
