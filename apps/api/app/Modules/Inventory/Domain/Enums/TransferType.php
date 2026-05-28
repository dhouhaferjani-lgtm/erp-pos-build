<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Type of stock transfer.
 *
 * Intracompany — same company, between two locations of that company.
 * Intercompany — across two legal companies within the same tenant
 *                (deferred to a follow-on track per T1 spec Scenario B;
 *                included here so the column exists and the enum is stable
 *                from day one).
 */
enum TransferType: string
{
    case Intracompany = 'intracompany';
    case Intercompany = 'intercompany';

    public function label(): string
    {
        return match ($this) {
            self::Intracompany => 'Intra-company',
            self::Intercompany => 'Inter-company',
        };
    }
}
