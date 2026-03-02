<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Domain\Enums;

enum PromotionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Expired = 'expired';
    case Archived = 'archived';
}
