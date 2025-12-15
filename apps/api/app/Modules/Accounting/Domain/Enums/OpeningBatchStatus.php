<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

enum OpeningBatchStatus: string
{
    case Draft = 'DRAFT';
    case Validated = 'VALIDATED';
    case Locked = 'LOCKED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Validated => 'Validated',
            self::Locked => 'Locked',
        };
    }

    /**
     * Check if the batch can be edited
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Check if the batch can be deleted
     */
    public function isDeletable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Check if the batch can be posted
     */
    public function canPost(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Check if the batch can be locked
     */
    public function canLock(): bool
    {
        return $this === self::Validated;
    }

    /**
     * Check if the batch is immutable
     */
    public function isImmutable(): bool
    {
        return $this === self::Locked;
    }
}
