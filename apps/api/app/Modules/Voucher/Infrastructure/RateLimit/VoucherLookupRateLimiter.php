<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\RateLimit;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Voucher\Domain\Exceptions\VoucherRateLimitedException;
use Illuminate\Cache\RateLimiter;

/**
 * Layered rate limiter for the voucher lookup endpoint (spec §4.7).
 *
 * All counters are Redis-backed in production (file/array in tests).
 * Each counter uses a separate cache key with its own TTL (day or hour).
 *
 * Limits (defaults, all configurable via config/voucher.php):
 *   - Per-terminal/day:               200 hard block
 *   - Per-cashier/day:                100 hard block
 *   - Per-tenant/hour (failed only):  soft alert at 50, hard block at 200
 *   - Per-IP/hour:                    300 hard block
 *   - Per-code-prefix/hour:           30  hard block
 *   - Per-voucher failed/24h:          5  → auto-void + VoucherFraudAlert
 *
 * IMPORTANT: callers MUST never surface specific rate-limit messages to end users.
 * On any VoucherRateLimitedException, return GenericLookupResult::invalid().
 */
final class VoucherLookupRateLimiter
{
    /** Max successful+failed lookups per terminal per day */
    private const PER_TERMINAL_DAY = 200;

    /** Max lookups per cashier per day */
    private const PER_CASHIER_DAY = 100;

    /** Failed lookups per tenant per hour before hard block */
    private const PER_TENANT_FAILED_HOUR_HARD = 200;

    /** Max lookups per IP per hour */
    private const PER_IP_HOUR = 300;

    /** Max lookups per code-prefix (first 4 chars after tenant prefix) per hour */
    private const PER_PREFIX_HOUR = 30;

    /** Failed attempts per voucher per 24h before auto-void */
    public const PER_VOUCHER_FAILED_24H = 5;

    /** One hour in seconds */
    private const TTL_HOUR = 3600;

    /** One day in seconds */
    private const TTL_DAY = 86400;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
    ) {}

    /**
     * Check that the terminal has not exceeded its daily lookup limit.
     *
     * @throws VoucherRateLimitedException
     */
    public function checkPerTerminal(string $terminalId): void
    {
        $key = "voucher_lookup:terminal:{$terminalId}:".date('Y-m-d');

        if ($this->rateLimiter->tooManyAttempts($key, self::PER_TERMINAL_DAY)) {
            throw new VoucherRateLimitedException(
                "Terminal {$terminalId} exceeded daily voucher lookup limit."
            );
        }

        $this->rateLimiter->hit($key, self::TTL_DAY);
    }

    /**
     * Check that the cashier has not exceeded their daily lookup limit.
     *
     * @throws VoucherRateLimitedException
     */
    public function checkPerCashier(string $cashierId): void
    {
        $key = "voucher_lookup:cashier:{$cashierId}:".date('Y-m-d');

        if ($this->rateLimiter->tooManyAttempts($key, self::PER_CASHIER_DAY)) {
            throw new VoucherRateLimitedException(
                "Cashier {$cashierId} exceeded daily voucher lookup limit."
            );
        }

        $this->rateLimiter->hit($key, self::TTL_DAY);
    }

    /**
     * Check that the tenant has not exceeded its per-hour failed-lookup hard block.
     *
     * This counter is incremented ONLY on failed lookups (see recordTenantFailedAttempt).
     *
     * @throws VoucherRateLimitedException
     */
    public function checkPerTenantFailed(string $tenantId): void
    {
        $key = "voucher_lookup:tenant_failed:{$tenantId}:".date('Y-m-d-H');

        if ($this->rateLimiter->tooManyAttempts($key, self::PER_TENANT_FAILED_HOUR_HARD)) {
            throw new VoucherRateLimitedException(
                "Tenant {$tenantId} exceeded per-hour failed voucher lookup limit."
            );
        }
    }

    /**
     * Record a failed lookup from the tenant perspective.
     * Must be called whenever a lookup returns "not found" or "invalid".
     */
    public function recordTenantFailedAttempt(string $tenantId): void
    {
        $key = "voucher_lookup:tenant_failed:{$tenantId}:".date('Y-m-d-H');
        $this->rateLimiter->hit($key, self::TTL_HOUR);
    }

    /**
     * Check the per-IP/API-token hourly lookup limit.
     *
     * @throws VoucherRateLimitedException
     */
    public function checkPerIp(string $ipAddress): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9._:-]/', '_', $ipAddress);
        $key = "voucher_lookup:ip:{$safe}:".date('Y-m-d-H');

        if ($this->rateLimiter->tooManyAttempts($key, self::PER_IP_HOUR)) {
            throw new VoucherRateLimitedException(
                "IP {$ipAddress} exceeded hourly voucher lookup limit."
            );
        }

        $this->rateLimiter->hit($key, self::TTL_HOUR);
    }

    /**
     * Check the per-code-prefix hourly lookup limit.
     *
     * The prefix is the first 4 characters of the code after stripping the tenant
     * prefix (first 4 chars). For a code like "POSC-XYZW-1234", the lookup prefix
     * is "XYZW". If the code is too short, use the raw code.
     *
     * @throws VoucherRateLimitedException
     */
    public function checkPerCodePrefix(string $codePrefix): void
    {
        $key = "voucher_lookup:prefix:{$codePrefix}:".date('Y-m-d-H');

        if ($this->rateLimiter->tooManyAttempts($key, self::PER_PREFIX_HOUR)) {
            throw new VoucherRateLimitedException(
                "Code prefix {$codePrefix} exceeded hourly voucher lookup limit."
            );
        }

        $this->rateLimiter->hit($key, self::TTL_HOUR);
    }

    /**
     * Record a failed lookup attempt against a specific voucher.
     *
     * Returns the new failed-attempt count for the voucher within the 24h window.
     * When the count reaches PER_VOUCHER_FAILED_24H (5), the caller must auto-void
     * the voucher and dispatch VoucherFraudAlert.
     */
    public function recordFailedAttempt(string $voucherId): int
    {
        $key = "voucher_lookup:voucher_failed:{$voucherId}";
        $this->rateLimiter->hit($key, self::TTL_DAY);

        return $this->rateLimiter->attempts($key);
    }

    /**
     * Run all checks in one call (convenience method).
     *
     * Order: IP → terminal → cashier → tenant-failed → code-prefix.
     * Checks are ordered cheapest-first; tenant-failed check is read-only here
     * (incrementing happens separately via recordTenantFailedAttempt).
     *
     * @throws VoucherRateLimitedException on the first tripped counter
     */
    public function checkAll(string $codePrefix, Terminal $terminal, User $cashier, ?string $ipAddress): void
    {
        if ($ipAddress !== null && $ipAddress !== '') {
            $this->checkPerIp($ipAddress);
        }

        $this->checkPerTerminal($terminal->id);
        $this->checkPerCashier($cashier->id);
        $this->checkPerTenantFailed($terminal->tenant_id);
        $this->checkPerCodePrefix($codePrefix);
    }
}
