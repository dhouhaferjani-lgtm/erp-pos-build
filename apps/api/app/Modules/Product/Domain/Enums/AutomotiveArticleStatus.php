<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum AutomotiveArticleStatus: string
{
    case Active = 'active';
    case Discontinued = 'discontinued';
    case Superseded = 'superseded';
    case PendingReview = 'pending_review';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Discontinued => 'Discontinued',
            self::Superseded => 'Superseded',
            self::PendingReview => 'Pending Review',
        };
    }
}
