<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a CustomerBound voucher is redeemed by a customer other than
 * the partner identified at issuance (issued_to_partner_id).
 */
final class VoucherNotForThisCustomerException extends RuntimeException
{
    public function __construct(string $voucherCode, string $requestedPartnerId, string $issuedToPartnerId)
    {
        parent::__construct(sprintf(
            "Voucher '%s' is bound to customer '%s' and cannot be redeemed by customer '%s'.",
            $voucherCode,
            $issuedToPartnerId,
            $requestedPartnerId
        ));
    }
}
