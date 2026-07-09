<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Resolves whether a country has an active regulatory below-cost floor
 * (FR/TN "cannot sell below cost"). Phase 1 treats these as ADVISORY-only,
 * regardless of the stored enforcement, until counsel sign-off (spec Rev 3 §8).
 */
interface RegulatoryFloorResolverInterface
{
    public function belowCostFloorActive(string $countryCode): bool;
}
