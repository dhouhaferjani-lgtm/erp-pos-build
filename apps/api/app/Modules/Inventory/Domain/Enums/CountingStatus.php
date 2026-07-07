<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum CountingStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Count1InProgress = 'count_1_in_progress';
    case Count1Completed = 'count_1_completed';
    case Count2InProgress = 'count_2_in_progress';
    case Count2Completed = 'count_2_completed';
    case Count3InProgress = 'count_3_in_progress';
    case Count3Completed = 'count_3_completed';
    case PendingReview = 'pending_review';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [
            self::Count1InProgress,
            self::Count2InProgress,
            self::Count3InProgress,
        ], true);
    }

    public function isCompleted(): bool
    {
        return $this === self::Finalized;
    }

    /**
     * @return array<CountingStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Count1InProgress, self::Cancelled],
            self::Scheduled => [self::Count1InProgress, self::Cancelled],
            self::Count1InProgress => [self::Count1Completed, self::Cancelled],
            self::Count1Completed => [self::Count2InProgress, self::PendingReview, self::Cancelled],
            self::Count2InProgress => [self::Count2Completed, self::Cancelled],
            self::Count2Completed => [self::Count3InProgress, self::PendingReview, self::Cancelled],
            self::Count3InProgress => [self::Count3Completed, self::Cancelled],
            self::Count3Completed => [self::PendingReview, self::Cancelled],
            self::PendingReview => [self::Finalized, self::Count3InProgress, self::Cancelled],
            self::Finalized => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
