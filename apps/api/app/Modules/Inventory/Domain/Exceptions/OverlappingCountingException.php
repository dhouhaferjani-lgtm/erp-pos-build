<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * Thrown when a counting is activated (or re-validated at finalize) while one
 * of its items overlaps another counting that is currently active on the
 * same (product_id, location_id, variant_id) grain.
 *
 * Two active counts covering the same stock grain in parallel would produce
 * conflicting theoretical/resolved quantities for the same movement window —
 * the replay boundary of one count could straddle the other's counted
 * window. Extends DomainException so it is mapped to a 422 BUSINESS_ERROR by
 * the generic DomainException handler in bootstrap/app.php, matching the
 * module's existing exception-mapping pattern (see TransferStateException).
 */
class OverlappingCountingException extends DomainException
{
    public function __construct(
        public readonly string $countingId,
        public readonly string $conflictingCountingId,
        public readonly string $productId,
        public readonly string $locationId,
        public readonly ?string $variantId,
    ) {
        $variantPart = $variantId !== null ? " (variant {$variantId})" : '';

        parent::__construct(
            "Cannot activate/finalize counting {$countingId}: product {$productId} at ".
            "location {$locationId}{$variantPart} is already covered by active counting ".
            "{$conflictingCountingId}"
        );
    }
}
