<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Domain\Location;

/**
 * Resolves the EFFECTIVE POS stock policy for a location (spec §4.2, live
 * inventory counting task A4). Pure domain function — no dependencies, so it
 * can be instantiated directly (`new LocationStockPolicyResolver`) from
 * anywhere, including JsonResources, without container access.
 *
 * Resolution order: a location in onboarding mode (sell-before-count) ALWAYS
 * resolves to Off, regardless of any override — the whole point of
 * onboarding mode is to let sales continue while the opening stock count is
 * still in progress. Otherwise the location's own override wins; absent
 * that, the company-wide policy applies.
 *
 * Contract: this signature is consumed by later tasks (B3, C2) — keep it
 * exact.
 */
final class LocationStockPolicyResolver
{
    public function resolve(Location $location): PosStockPolicy
    {
        if ($location->onboarding_mode) {
            return PosStockPolicy::Off;
        }

        $override = $location->pos_stock_policy_override;
        if (is_string($override) && $override !== '') {
            $overridePolicy = PosStockPolicy::tryFrom($override);
            if ($overridePolicy !== null) {
                return $overridePolicy;
            }
        }

        return $location->company->pos_stock_policy;
    }
}
