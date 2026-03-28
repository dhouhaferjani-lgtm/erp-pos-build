<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum EnrichmentReviewStatus: string
{
    case PendingReview = 'pending_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending Review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
        };
    }
}
