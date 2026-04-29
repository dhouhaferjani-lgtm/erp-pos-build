<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

/**
 * Result of a generic (outside-session) voucher lookup.
 *
 * Per spec §4.7 disclosure boundary:
 *   - Never includes balance, expiry, customer details, or redemption mode.
 *   - The `exists: false, status: 'invalid'` response is IDENTICAL for both
 *     "code not found" and "rate-limited" paths — preventing enumeration attacks.
 *
 * Possible statuses:
 *   'active'   — voucher exists and is currently redeemable
 *   'expired'  — voucher exists but is expired or fully redeemed
 *   'invalid'  — voucher does not exist, OR was voided, OR rate-limit tripped
 */
final class GenericLookupResult
{
    private function __construct(
        public readonly bool $exists,
        public readonly string $status,
    ) {}

    public static function active(): self
    {
        return new self(exists: true, status: 'active');
    }

    public static function expired(): self
    {
        return new self(exists: true, status: 'expired');
    }

    public static function invalid(): self
    {
        return new self(exists: false, status: 'invalid');
    }

    /**
     * @return array{exists: bool, status: string}
     */
    public function toArray(): array
    {
        return [
            'exists' => $this->exists,
            'status' => $this->status,
        ];
    }
}
