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
 *
 * ExchangeDeferred is a special-purpose value used exclusively by ExchangeService
 * to signal that the return half of an exchange should NOT trigger any payment
 * side effect. The net settlement (cash out / surplus voucher) is calculated and
 * executed by ExchangeService after both halves are created.
 */
enum RefundDestination: string
{
    case OriginalPayment = 'original_payment';
    case Cash = 'cash';
    case StoreVoucher = 'store_voucher';
    /** Used only by ExchangeService — skips payment side effect on the return half */
    case ExchangeDeferred = 'exchange_deferred';
}
