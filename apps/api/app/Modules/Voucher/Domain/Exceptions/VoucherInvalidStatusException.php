<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use RuntimeException;

/**
 * Thrown when a redemption is attempted on a voucher whose current status
 * does not permit redemption (i.e. not Issued or PartiallyRedeemed),
 * or when the voucher currency does not match the redemption request currency.
 *
 * Lane Q-5 extends it to the void edge as well, so the Voucher module keeps ONE
 * typed dialect for "this voucher cannot make that transition" (sweep finding
 * #29 warns against a second dialect inside the same module). `$errorCode`
 * carries the machine-readable discriminator the Presentation layer renders;
 * it stays null for the pre-existing redemption refusals.
 */
final class VoucherInvalidStatusException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null)
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

    /**
     * The void edge is open only from Issued / PartiallyRedeemed. FullyRedeemed,
     * Expired and Voided are terminal for it (sweep finding #24).
     */
    public static function notVoidable(string $voucherCode, VoucherStatus $actualStatus): self
    {
        return new self(sprintf(
            "Voucher '%s' cannot be voided: current status is '%s'. "
            .'Only Issued or PartiallyRedeemed vouchers are voidable.',
            $voucherCode,
            $actualStatus->value
        ), 'VOUCHER_NOT_VOIDABLE');
    }

    /**
     * A voucher that has been redeemed cannot be voided — voiding it would
     * reverse a liability that was already extinguished by the redemption.
     */
    public static function hasRedemptions(string $voucherCode): self
    {
        return new self(sprintf(
            "Voucher '%s' cannot be voided: it has been partially or fully redeemed.",
            $voucherCode
        ), 'VOUCHER_HAS_REDEMPTIONS');
    }

    /**
     * The voucher disappeared between the caller's read and the locked re-read,
     * or the caller's tenant/company scope does not own it.
     */
    public static function notFound(string $voucherId): self
    {
        return new self(sprintf(
            "Voucher '%s' does not exist in the current scope.",
            $voucherId
        ), 'VOUCHER_NOT_FOUND');
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
