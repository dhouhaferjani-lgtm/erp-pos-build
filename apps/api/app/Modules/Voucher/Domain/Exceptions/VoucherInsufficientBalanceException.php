<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when the requested redemption amount exceeds the voucher's current balance.
 */
final class VoucherInsufficientBalanceException extends RuntimeException
{
    /**
     * @param  numeric-string  $requestedAmount
     * @param  numeric-string  $currentBalance
     */
    public function __construct(string $voucherCode, string $requestedAmount, string $currentBalance)
    {
        parent::__construct(sprintf(
            "Voucher '%s' has insufficient balance: requested %s but current balance is %s.",
            $voucherCode,
            $requestedAmount,
            $currentBalance
        ));
    }
}
