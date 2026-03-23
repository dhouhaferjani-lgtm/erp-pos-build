<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum VatDirection: string
{
    case Output = 'OUTPUT';
    case Input = 'INPUT';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Output => 'Output',
            self::Input => 'Input',
        };
    }
}
