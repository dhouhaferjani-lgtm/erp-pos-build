<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use Illuminate\Support\Carbon;

/**
 * Result of an in-session (payment flow) voucher lookup.
 *
 * Per spec §4.7 disclosure boundary:
 *   - Full details are returned only when a cart/receipt is actively open.
 *   - On any failure (rate-limit or voucher invalid), the caller receives
 *     GenericLookupResult::invalid() — never this object.
 *
 * `partnerIdMatch` is true when the voucher is CustomerBound AND the
 * in-session partner matches the voucher's issued_to_partner_id.
 * For Bearer vouchers, `partnerIdMatch` is always true.
 */
final class InSessionLookupResult
{
    /**
     * @param  numeric-string  $balance
     */
    public function __construct(
        public readonly string $voucherId,
        public readonly string $balance,
        public readonly string $currency,
        public readonly RedemptionMode $redemptionMode,
        public readonly ?Carbon $expiresAt,
        public readonly bool $partnerIdMatch,
    ) {}

    /**
     * @return array{voucher_id: string, balance: string, currency: string, redemption_mode: string, expires_at: string|null, partner_id_match: bool}
     */
    public function toArray(): array
    {
        return [
            'voucher_id' => $this->voucherId,
            'balance' => $this->balance,
            'currency' => $this->currency,
            'redemption_mode' => $this->redemptionMode->value,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'partner_id_match' => $this->partnerIdMatch,
        ];
    }
}
