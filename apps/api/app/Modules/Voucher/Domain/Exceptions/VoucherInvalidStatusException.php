<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use RuntimeException;

/**
 * Thrown when a redemption is attempted on a voucher whose current status
 * does not permit redemption (i.e. not Issued or PartiallyRedeemed),
 * or when the voucher currency does not match the redemption request currency.
 */
final class VoucherInvalidStatusException extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function invalidStatus(string $voucherCode, VoucherStatus $actualStatus): self
    {
        return new self(sprintf(
            "Voucher '%s' cannot be redeemed: current status is '%s'. "
            .'Only Issued or PartiallyRedeemed vouchers are redeemable.',
            $voucherCode,
            $actualStatus->value
        ));
    }

    public static function currencyMismatch(string $voucherCode, string $voucherCurrency, string $requestCurrency): self
    {
        return new self(sprintf(
            "Voucher '%s' is denominated in %s but the redemption request specifies %s. "
            .'Currency must match exactly.',
            $voucherCode,
            $voucherCurrency,
            $requestCurrency
        ));
    }
}
