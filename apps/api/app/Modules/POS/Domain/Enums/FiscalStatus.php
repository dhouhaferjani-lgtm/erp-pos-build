<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum FiscalStatus: string
{
    case PendingSeal = 'pending_seal';
    case Fiscalized = 'fiscalized';
    case Voided = 'voided';
    case PendingSync = 'pending_sync';
    case Synced = 'synced';
    case SyncFailed = 'sync_failed';
}
