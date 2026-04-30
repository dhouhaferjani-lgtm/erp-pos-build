<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when the same voucher is applied twice on the same sale receipt.
 *
 * This guard prevents double-spend within a single transaction by checking
 * VoucherLedger for a prior Redeemed event with the same receipt_id.
 */
final class VoucherDuplicateInTransactionException extends RuntimeException
{
    public function __construct(string $voucherCode, string $receiptId)
    {
        parent::__construct(sprintf(
            "Voucher '%s' has already been applied to receipt '%s'. "
            .'A voucher may only be redeemed once per transaction.',
            $voucherCode,
            $receiptId
        ));
    }
}
