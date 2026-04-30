<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller attempts to issue or redeem a gift card through the
 * store-voucher issuance / redemption services.
 *
 * Gift-card support is deferred to Phase 2. In Phase 1 only store_voucher
 * instruments are handled by VoucherIssuanceService and VoucherRedemptionService.
 */
final class GiftCardNotYetSupportedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Gift cards are not yet supported in Phase 1. '
            .'Gift-card issuance and redemption ships in Phase 2. '
            .'In Phase 1, only store_voucher instruments are handled by '
            .'VoucherIssuanceService and VoucherRedemptionService.'
        );
    }
}
