<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

/**
 * Status of a receipt sync operation.
 *
 * Used in batch sync responses to indicate per-receipt outcome.
 */
enum SyncStatus: string
{
    case Synced = 'synced';
    case Duplicate = 'duplicate';
    case Failed = 'failed';
    case ChainBroken = 'chain_broken';

    public function label(): string
    {
        return match ($this) {
            self::Synced => 'Successfully synced',
            self::Duplicate => 'Already synced (duplicate idempotency key)',
            self::Failed => 'Sync failed',
            self::ChainBroken => 'Chain broken by earlier receipt failure',
        };
    }
}
