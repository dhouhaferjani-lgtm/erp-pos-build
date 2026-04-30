<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a redemption is attempted at a terminal that does not match
 * the voucher's redeemable_at_terminal_id.
 *
 * Phase 1 single-terminal scope: a voucher is always redeemable only at the
 * terminal where it was issued. This is enforced at the service layer.
 */
final class VoucherNotForThisTerminalException extends RuntimeException
{
    public function __construct(string $voucherCode, string $requestedTerminalId, string $allowedTerminalId)
    {
        parent::__construct(sprintf(
            "Voucher '%s' is not redeemable at terminal '%s'. "
            ."It is restricted to terminal '%s' (Phase 1 single-terminal scope).",
            $voucherCode,
            $requestedTerminalId,
            $allowedTerminalId
        ));
    }
}
