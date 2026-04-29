<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller attempts to issue or redeem a restaurant voucher
 * (ticket-restaurant) through the store-voucher issuance / redemption services.
 *
 * Restaurant-ticket tender is a Phase 2 feature (spec §3.2.1). In Phase 1 the
 * `instrument_type = 'restaurant_voucher'` value may still be written to
 * pos_receipt_payments (legacy path for existing restaurant-voucher serial rows),
 * but the VoucherIssuanceService and VoucherRedemptionService explicitly reject
 * any request that implies a restaurant-voucher instrument kind.
 */
final class RestaurantVoucherNotYetSupportedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Restaurant vouchers (ticket-restaurant) are not yet supported in Phase 1. '
            .'The full restaurant-ticket tender (RestaurantTicketTenderService) ships in Phase 2 '
            .'per spec §3.2.1. In Phase 1, only store_voucher instruments are handled by '
            .'VoucherIssuanceService and VoucherRedemptionService.'
        );
    }
}
