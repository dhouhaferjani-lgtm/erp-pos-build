<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

/**
 * One location's stock position for a product (+ optional variant) in the
 * cross-location distribution view (Task B4).
 *
 * On-hand and incoming-transfer are quantity-scale-4 numeric STRINGS; the
 * incoming term counts only in-transit stock-transfer quantities whose
 * destination is this location.
 */
final readonly class StockDistributionRowDTO
{
    /**
     * @param  string  $locationId  UUID of the location
     * @param  string  $locationName  Human-readable location name
     * @param  string  $locationType  Location type value ('shop' | 'warehouse')
     * @param  bool  $isCurrent  Whether this is the caller's current location
     * @param  string  $onHand  available = quantity − reserved, numeric string at scale 4
     * @param  string  $incomingTransfer  In-transit incoming transfer quantity, numeric string at scale 4
     */
    public function __construct(
        public string $locationId,
        public string $locationName,
        public string $locationType,
        public bool $isCurrent,
        public string $onHand,
        public string $incomingTransfer,
    ) {}
}
