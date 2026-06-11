<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

/**
 * Incoming stock toward the requested location for one (product, variant) key.
 *
 * - incomingTransfer: sum of in-transit stock-transfer line quantities whose
 *   destination is this location (variant-grain — lines carry variant_id).
 * - incomingPo: unreceived remainder of confirmed purchase-order lines at this
 *   location. The PO term is PRODUCT-grain: it always lands on the
 *   variantId = null row for the product (purchase receiving is reconciled at
 *   product grain in this read model), so a row whose variantId is non-null
 *   always has incomingPo "0.0000".
 */
final readonly class LocationIncomingRowDTO
{
    /**
     * @param  string  $incomingTransfer  Numeric string at quantity scale 4
     * @param  string  $incomingPo  Numeric string at quantity scale 4
     */
    public function __construct(
        public string $productId,
        public ?string $variantId,
        public string $incomingTransfer,
        public string $incomingPo,
    ) {}
}
