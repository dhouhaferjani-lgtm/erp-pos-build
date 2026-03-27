<?php

declare(strict_types=1);

namespace App\Modules\Progression\Domain\Enums;

enum RecommendationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
}
