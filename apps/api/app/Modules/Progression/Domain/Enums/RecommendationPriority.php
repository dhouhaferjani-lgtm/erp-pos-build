<?php

declare(strict_types=1);

namespace App\Modules\Progression\Domain\Enums;

enum RecommendationPriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function sortOrder(): int
    {
        return match ($this) {
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
        };
    }
}
