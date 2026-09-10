<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferReceiptFailureReason: string
{
    case OverReceipt = 'OVER_RECEIPT';
    case LotOverReceipt = 'LOT_OVER_RECEIPT';
    case UnknownLot = 'UNKNOWN_LOT';
    case LotRequired = 'LOT_REQUIRED';
    case LotNotAllowed = 'LOT_NOT_ALLOWED';
    case LotSumMismatch = 'LOT_SUM_MISMATCH';
    case LotTrackingMismatch = 'LOT_TRACKING_MISMATCH';
    case LineNotOnTransfer = 'LINE_NOT_ON_TRANSFER';
    case NothingToReceive = 'NOTHING_TO_RECEIVE';
    case DiscrepancyReasonRequired = 'DISCREPANCY_REASON_REQUIRED';
    case DiscrepancyReasonInvalid = 'DISCREPANCY_REASON_INVALID';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case DispositionRequired = 'DISPOSITION_REQUIRED';
    case BlindRequiresCountedReceipt = 'BLIND_REQUIRES_COUNTED_RECEIPT';
    case ValuationModeUnsupported = 'VALUATION_MODE_UNSUPPORTED';

    /** Static sentences only: no quantity, no transfer field, ever. */
    public function message(): string
    {
        return match ($this) {
            self::OverReceipt => 'The submitted quantity exceeds what is still open on this line.',
            self::LotOverReceipt => 'The submitted quantity exceeds what is still open on this lot.',
            self::UnknownLot => 'That lot was not shipped on this line.',
            self::LotRequired => 'This line is lot-tracked and requires lot detail.',
            self::LotNotAllowed => 'This line is not lot-tracked and accepts no lot detail.',
            self::LotSumMismatch => 'The lot quantities do not add up to the line quantities.',
            self::LotTrackingMismatch => 'The lot tracking of this line no longer matches the product.',
            self::LineNotOnTransfer => 'That line does not belong to this transfer.',
            self::NothingToReceive => 'There is nothing left to receive on this line.',
            self::DiscrepancyReasonRequired => 'A discrepancy reason is required when damage is declared.',
            self::DiscrepancyReasonInvalid => 'That discrepancy reason is not allowed for this action.',
            self::IdempotencyKeyReused => 'That idempotency key was already used for a different request.',
            self::DispositionRequired => 'A close disposition is required.',
            self::BlindRequiresCountedReceipt => 'This transfer must be received with counted quantities.',
            self::ValuationModeUnsupported => 'This action requires perpetual inventory valuation.',
        };
    }
}
