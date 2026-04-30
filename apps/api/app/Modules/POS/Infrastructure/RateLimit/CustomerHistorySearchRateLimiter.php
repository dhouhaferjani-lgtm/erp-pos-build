<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\RateLimit;

use Illuminate\Cache\RateLimiter;

/**
 * Per-cashier daily rate limiter for customer-history searches.
 *
 * Spec §2.6 / §4.7: limit from ReservationSettings.customerHistorySearchMaxPerCashierPerDay.
 *
 * Counter is Redis-backed in production (file/array in tests).
 * A single counter per cashier per calendar day tracks all searches regardless
 * of search input type.
 *
 * Returns false (does not throw) when the limit is exceeded, so the caller
 * can return an empty result set instead of an error — this prevents the
 * cashier from using the rate-limit error as a signal.
 */
final class CustomerHistorySearchRateLimiter
{
    private const TTL_DAY = 86400;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
    ) {}

    /**
     * Check whether the cashier can perform another search today.
     *
     * Returns true when under the limit, false when exceeded.
     * The counter is incremented only when the search is allowed.
     */
    public function checkAndIncrement(string $cashierId, int $maxPerDay): bool
    {
        $key = "customer_history_search:cashier:{$cashierId}:".date('Y-m-d');

        if ($this->rateLimiter->tooManyAttempts($key, $maxPerDay)) {
            return false;
        }

        $this->rateLimiter->hit($key, self::TTL_DAY);

        return true;
    }

    /**
     * Return the number of searches the cashier has performed today.
     */
    public function countToday(string $cashierId): int
    {
        $key = "customer_history_search:cashier:{$cashierId}:".date('Y-m-d');

        return $this->rateLimiter->attempts($key);
    }
}
