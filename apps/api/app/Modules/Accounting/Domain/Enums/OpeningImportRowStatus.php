<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

enum OpeningImportRowStatus: string
{
    case Pending = 'PENDING';
    case Valid = 'VALID';
    case Invalid = 'INVALID';
    case Skipped = 'SKIPPED';
    case Posted = 'POSTED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Validation',
            self::Valid => 'Valid',
            self::Invalid => 'Invalid',
            self::Skipped => 'Skipped',
            self::Posted => 'Posted',
        };
    }

    /**
     * Check if the row can be modified
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Pending, self::Valid, self::Invalid], true);
    }

    /**
     * Check if the row can be posted
     */
    public function canPost(): bool
    {
        return $this === self::Valid;
    }

    /**
     * Check if the row requires attention (has errors)
     */
    public function requiresAttention(): bool
    {
        return $this === self::Invalid;
    }
}
