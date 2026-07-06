<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Inventory;

/**
 * Cross-module reservation release keyed by source. Scalar-only signature by
 * design (deptrac: SharedContracts must not depend on module Domain enums) —
 * $sourceType / $reason carry the backing values of the Inventory enums.
 */
interface ReservationReleaserInterface
{
    public function releaseReservationsForSource(
        string $sourceType,
        string $sourceId,
        string $reason,
        ?string $releasedBy = null,
        ?string $expectedTenantId = null,
        ?string $expectedCompanyId = null,
    ): int;
}
