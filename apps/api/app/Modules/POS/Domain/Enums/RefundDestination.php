<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

/**
 * Where the refunded value should be returned to the customer.
 *
 * Used by RefundDestinationResolver to enforce tenant-level refund-destination
 * policy (spec §4.6). The resolver may override the cashier's requested
 * destination (e.g. forcing StoreVoucher when out-of-window policy is
 * voucher_only) or throw RefundDestinationNotAllowedException when the
 * requested destination is prohibited.
 */
enum RefundDestination: string
{
    case OriginalPayment = 'original_payment';
    case Cash = 'cash';
    case StoreVoucher = 'store_voucher';
}
