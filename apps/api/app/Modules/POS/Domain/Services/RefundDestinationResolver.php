<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Exceptions\RefundDestinationNotAllowedException;
use App\Modules\POS\Domain\Receipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Server-side enforcement of the tenant's refund-destination policy (spec §4.6).
 *
 * Given the cashier's requested destination, the original receipt, the tenant
 * refund policy from ReservationSettings, and the cashier's Spatie permission
 * list, this resolver returns the actual allowed destination or throws
 * RefundDestinationNotAllowedException.
 *
 * Decision matrix:
 *   1. Compute whether the refund is out-of-window.
 *   2. Out-of-window + outOfWindowPolicy = 'voucher_only':
 *      force destination to StoreVoucher (override with warning log).
 *   3. Out-of-window + outOfWindowPolicy = 'refuse':
 *      block UNLESS cashier holds `pos.refund_above_threshold` (manager override).
 *      If override allowed, honour the requested destination (subject to rule 4).
 *   4. In-window (or override granted): validate requested is in
 *      policy.allowedRefundDestinations. If not, throw.
 *   5. Return the resolved destination.
 *
 * Usage: this resolver is called both server-side in the API endpoint AND can be
 * called by the UI to pre-compute available choices. A malicious client cannot
 * bypass out_of_window_policy = voucher_only because the API always runs the
 * resolver regardless of the frontend selection.
 */
final class RefundDestinationResolver
{
    /**
     * Resolve the actual allowed refund destination.
     *
     * @param  RefundDestination  $requested  The cashier's selection
     * @param  Receipt  $original  The original sale receipt
     * @param  ReservationSettings  $policy  The tenant's refund policy
     * @param  array<string>  $cashierPermissions  Spatie permission names held by the cashier
     *
     * @throws RefundDestinationNotAllowedException
     */
    public function resolve(
        RefundDestination $requested,
        Receipt $original,
        ReservationSettings $policy,
        array $cashierPermissions,
    ): RefundDestination {
        $outOfWindow = $this->isOutOfWindow($original, $policy);

        if ($outOfWindow) {
            return $this->resolveOutOfWindow($requested, $policy, $cashierPermissions);
        }

        return $this->resolveInWindow($requested, $policy);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function isOutOfWindow(Receipt $original, ReservationSettings $policy): bool
    {
        $windowEndsAt = $original->posted_at->copy()->addDays($policy->customerReturnExpiryDays);

        return Carbon::now()->isAfter($windowEndsAt);
    }

    /**
     * @param  array<string>  $cashierPermissions
     *
     * @throws RefundDestinationNotAllowedException
     */
    private function resolveOutOfWindow(
        RefundDestination $requested,
        ReservationSettings $policy,
        array $cashierPermissions,
    ): RefundDestination {
        $hasOverride = in_array('pos.refund_above_threshold', $cashierPermissions, true);

        return match ($policy->outOfWindowPolicy) {
            'voucher_only' => $this->forceStoreVoucher($requested),
            'refuse' => $this->refuseOrOverride($requested, $policy, $hasOverride),
            default => $this->resolveInWindow($requested, $policy),
        };
    }

    /**
     * Force destination to StoreVoucher when policy = voucher_only.
     * Logs a warning if the requested destination differs, for audit purposes.
     */
    private function forceStoreVoucher(RefundDestination $requested): RefundDestination
    {
        if ($requested !== RefundDestination::StoreVoucher) {
            Log::warning('RefundDestinationResolver: out-of-window voucher_only policy overrode requested destination.', [
                'requested' => $requested->value,
                'forced' => RefundDestination::StoreVoucher->value,
            ]);
        }

        return RefundDestination::StoreVoucher;
    }

    /**
     * Handle outOfWindowPolicy = 'refuse'. Block unless cashier has the override permission.
     *
     * @throws RefundDestinationNotAllowedException
     */
    private function refuseOrOverride(
        RefundDestination $requested,
        ReservationSettings $policy,
        bool $hasOverride,
    ): RefundDestination {
        if (! $hasOverride) {
            throw new RefundDestinationNotAllowedException(
                $requested,
                'The return window has expired and the tenant policy is set to refuse out-of-window refunds. '
                .'A manager with `pos.refund_above_threshold` permission is required to override.'
            );
        }

        // Manager override granted — still validate the requested destination against the allowed list.
        return $this->resolveInWindow($requested, $policy);
    }

    /**
     * Validate the requested destination against the allowed-destinations list.
     *
     * @throws RefundDestinationNotAllowedException
     */
    private function resolveInWindow(
        RefundDestination $requested,
        ReservationSettings $policy,
    ): RefundDestination {
        $allowedValues = $policy->allowedRefundDestinations;

        if (! in_array($requested->value, $allowedValues, true)) {
            throw new RefundDestinationNotAllowedException(
                $requested,
                sprintf(
                    "Destination '%s' is not in the tenant's allowed refund destinations: [%s].",
                    $requested->value,
                    implode(', ', $allowedValues)
                )
            );
        }

        return $requested;
    }
}
