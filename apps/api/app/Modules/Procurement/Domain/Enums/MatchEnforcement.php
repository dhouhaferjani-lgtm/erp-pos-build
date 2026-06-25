<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Enums;

/**
 * What happens when a supplier bill's amounts fall outside tolerance thresholds.
 *
 * Warn:  flag the discrepancy and allow posting (soft enforcement).
 * Block: prevent posting until the discrepancy is resolved (hard enforcement).
 */
enum MatchEnforcement: string
{
    case Warn = 'warn';
    case Block = 'block';
}
