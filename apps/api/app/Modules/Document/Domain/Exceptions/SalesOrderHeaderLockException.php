<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use RuntimeException;

/**
 * The sales-order header row could not be locked before the delivery-note sequence —
 * an INTEGRITY ALARM, never a customer-data refusal.
 *
 * SalesOrderToDeliveryNoteConverter::lockOrderHeader() asserts its `FOR UPDATE` matched
 * exactly one row; a miss means the L1<L2 ordering guarantee silently did not happen
 * (tenant/company/type drift, or a concurrent delete). A bare RuntimeException cannot be
 * used for this: DocumentConversionController::convertOrderToDelivery() already throws
 * RuntimeException for routine 422 refusals, so the controller distinguishes this alarm
 * by TYPE and rethrows it past its catch-all — the same 500-class disposition
 * DeliveryNoteClaimNotFinalisedException established. (M5-terminal treasury r3.)
 */
final class SalesOrderHeaderLockException extends RuntimeException
{
    public static function forOrder(string $orderId): self
    {
        return new self(sprintf(
            'Lock order violation: the sales-order header %s could not be locked before the delivery-note sequence.',
            $orderId,
        ));
    }
}
