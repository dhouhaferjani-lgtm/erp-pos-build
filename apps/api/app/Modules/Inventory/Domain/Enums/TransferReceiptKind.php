<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferReceiptKind: string
{
    case Receipt = 'receipt';
    case Close = 'close';
    /** Backfill only — never written by the service, never carried by an event. */
    case LegacyCompletion = 'legacy_completion';
}
