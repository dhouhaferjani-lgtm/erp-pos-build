<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

enum ImportRowOutcome: string
{
    case Pending = 'pending';
    case Imported = 'imported';
    case DuplicateSkipped = 'duplicate_skipped';
    case DuplicateLoser = 'duplicate_loser';
    case Failed = 'failed';
    case OpeningLocked = 'opening_locked';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending => false,
            self::Imported,
            self::DuplicateSkipped,
            self::DuplicateLoser,
            self::Failed,
            self::OpeningLocked => true,
        };
    }
}
