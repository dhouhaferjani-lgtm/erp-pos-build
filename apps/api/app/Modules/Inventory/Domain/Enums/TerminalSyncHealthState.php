<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TerminalSyncHealthState: string
{
    case Healthy = 'healthy';
    case Pending = 'pending';
    case Stale = 'stale';
    case Unknown = 'unknown';
}
