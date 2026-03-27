<?php

declare(strict_types=1);

namespace App\Modules\Progression\Domain\Enums;

enum GrowthStage: string
{
    case Launch = 'launch';
    case Stabilize = 'stabilize';
    case Optimize = 'optimize';
    case Expand = 'expand';

    public function label(): string
    {
        return match ($this) {
            self::Launch => 'Launch',
            self::Stabilize => 'Stabilize',
            self::Optimize => 'Optimize',
            self::Expand => 'Expand',
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::Launch => 1,
            self::Stabilize => 2,
            self::Optimize => 3,
            self::Expand => 4,
        };
    }
}
