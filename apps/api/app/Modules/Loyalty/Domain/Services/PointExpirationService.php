<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Services;

use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\ValueObjects\PointsAmount;
use App\Shared\Domain\CurrencyScale;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Service for handling point expiration logic
 *
 * This service provides methods to:
 * - Find transactions with expiring points
 * - Calculate expired point amounts
 * - Group points by expiration date
 * - Check expiration status
 * - Calculate days until expiration
 *
 * Supports FIFO (first-in-first-out) expiration and grace periods.
 */
final readonly class PointExpirationService
{
    /**
     * Find transactions with expiring points
     *
     * Returns all earned transactions where the expiration date (if set)
     * is on or before the specified date plus grace period.
     *
     * @param  Collection<int, Transaction>  $earnedTransactions
     * @param  Carbon  $asOfDate  The reference date to check expiration against
     * @param  int  $graceDays  Number of grace days to add (default: 0)
     * @return Collection<int, Transaction> Transactions with expiring points
     */
    public function findExpiringPoints(
        Collection $earnedTransactions,
        Carbon $asOfDate,
        int $graceDays = 0
    ): Collection {
        $cutoffDate = $asOfDate->copy()->addDays($graceDays);

        return $earnedTransactions->filter(function (Transaction $transaction) use ($cutoffDate) {
            // Exclude transactions without expiration dates
            if ($transaction->expires_at === null) {
                return false;
            }

            // Include if expires_at is on or before cutoff date
            return $transaction->expires_at->lessThanOrEqualTo($cutoffDate);
        });
    }

    /**
     * Calculate total points expiring
     *
     * Sums up the amount of all provided transactions.
     *
     * @param  Collection<int, Transaction>  $expiringTransactions
     * @return PointsAmount Total points expiring
     */
    public function calculateExpiringAmount(
        Collection $expiringTransactions
    ): PointsAmount {
        // bcmath accumulator at the canonical points scale — no float drift.
        $total = '0';
        foreach ($expiringTransactions as $transaction) {
            $total = bcadd(
                $total,
                CurrencyScale::bcformat((string) $transaction->amount, PointsAmount::SCALE),
                PointsAmount::SCALE,
            );
        }

        return PointsAmount::fromNumericString($total);
    }

    /**
     * Group expiring points by expiration date
     *
     * Returns an associative array where keys are date strings (Y-m-d format)
     * and values are the total points expiring on that date.
     *
     * Only includes transactions with expires_at within the specified date range.
     *
     * @param  Collection<int, Transaction>  $earnedTransactions
     * @param  Carbon  $fromDate  Start of date range (inclusive)
     * @param  Carbon  $toDate  End of date range (inclusive)
     * @return array<string, float> Date => Amount mapping
     */
    public function groupByExpirationDate(
        Collection $earnedTransactions,
        Carbon $fromDate,
        Carbon $toDate
    ): array {
        $grouped = [];

        foreach ($earnedTransactions as $transaction) {
            // Skip transactions without expiration date
            if ($transaction->expires_at === null) {
                continue;
            }

            // Skip transactions outside date range
            if ($transaction->expires_at->lessThan($fromDate) ||
                $transaction->expires_at->greaterThan($toDate)) {
                continue;
            }

            $dateKey = $transaction->expires_at->format('Y-m-d');
            $amount = (float) $transaction->amount;

            if (! isset($grouped[$dateKey])) {
                $grouped[$dateKey] = 0.0;
            }

            $grouped[$dateKey] += $amount;
        }

        return $grouped;
    }

    /**
     * Check if a transaction's points have expired
     *
     * Returns true if the transaction has an expiration date and it's before
     * the specified date.
     *
     * @param  Carbon  $asOfDate  The reference date to check against
     * @return bool True if expired, false otherwise
     */
    public function hasExpired(Transaction $transaction, Carbon $asOfDate): bool
    {
        return $transaction->expires_at !== null
            && $transaction->expires_at->isBefore($asOfDate);
    }

    /**
     * Calculate days until expiration
     *
     * Returns the number of days from asOfDate to the expiration date.
     * - Positive number: points will expire in N days
     * - Negative number: points expired N days ago
     * - Null: no expiration date set
     *
     * @param  Carbon  $asOfDate  The reference date to calculate from
     * @return int|null Days until expiration, or null if no expiration date
     */
    public function daysUntilExpiration(Transaction $transaction, Carbon $asOfDate): ?int
    {
        if ($transaction->expires_at === null) {
            return null;
        }

        return (int) $asOfDate->diffInDays($transaction->expires_at, false);
    }
}
