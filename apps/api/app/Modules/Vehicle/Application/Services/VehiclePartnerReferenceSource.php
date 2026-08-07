<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * Vehicles owned by the partner (Otospex).
 *
 * - `vehicles.partner_id` (FK SET NULL, soft-deletes): the customer's car.
 *   Losing the owner turns a serviced vehicle into an unattributable one.
 * - `vehicle_ownership_history.owner_partner_id` (FK RESTRICT, NOT NULL):
 *   the ownership-transfer audit trail, which the schema already refuses
 *   to let dangle on a hard delete.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class VehiclePartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('vehicles', ['partner_id'], hasSoftDeletes: true),
            new PartnerReferenceTable('vehicle_ownership_history', ['owner_partner_id']),
        ];
    }
}
