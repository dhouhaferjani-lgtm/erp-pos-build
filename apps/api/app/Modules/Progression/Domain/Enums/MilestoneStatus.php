<?php

declare(strict_types=1);

namespace App\Modules\Progression\Domain\Enums;

enum MilestoneStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Skipped => true,
            default => false,
        };
    }
}
