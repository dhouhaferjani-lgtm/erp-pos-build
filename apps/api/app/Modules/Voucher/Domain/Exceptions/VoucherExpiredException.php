<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a redemption is attempted on a voucher whose expires_at is in the past.
 */
final class VoucherExpiredException extends RuntimeException
{
    public function __construct(string $voucherCode, string $expiredAt)
    {
        parent::__construct(sprintf(
            "Voucher '%s' expired at %s and is no longer redeemable.",
            $voucherCode,
            $expiredAt
        ));
    }
}
