<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum GoodsReceiptFailureReason: string
{
    case OverReceipt = 'OVER_RECEIPT';
    case OverReceiptFree = 'OVER_RECEIPT_FREE';

    /**
     * Inbound lot capture, distinct from BatchRequiredForLineException:
     * that exception requires a negative adjustment to name the lot drawn down.
     */
    case BatchDataRequired = 'BATCH_DATA_REQUIRED';
    case VariantRequired = 'VARIANT_REQUIRED';
    case BatchExpiryConflict = 'BATCH_EXPIRY_CONFLICT';
    case ExpiredLotRefused = 'EXPIRED_LOT_REFUSED';
    case ReceivedPriceInvalid = 'RECEIVED_PRICE_INVALID';
    case NothingToReceive = 'NOTHING_TO_RECEIVE';
}
