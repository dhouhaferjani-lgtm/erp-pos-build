<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Domain\Enums;

enum SellerStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case PendingReview = 'pending_review';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::PendingReview => 'Pending Review',
        };
    }
}
