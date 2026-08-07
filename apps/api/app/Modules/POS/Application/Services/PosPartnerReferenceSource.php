<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * POS rows bound to the partner.
 *
 * - `pos_receipts` (FK SET NULL): fiscal receipts, immutable by design
 *   (NF525) and never deleted.
 * - `pos_orders` (FK NO ACTION): open/held orders that have not yet become
 *   a receipt — an order left on a table would still be tendered against
 *   the deleted customer.
 * - `pos_customer_aliases` (FK CASCADE on `server_partner_id`): the device
 *   `client_customer_uuid` -> server partner identity map. This one is
 *   CASCADE, i.e. schema-owned by the partner, but it is NOT descriptive
 *   configuration: while it survives, a POS device keeps resolving its
 *   local customer to the soft-deleted partner and keeps attaching new
 *   receipts to it. That is the exact orphaning this guard exists to stop.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class PosPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('pos_receipts'),
            new PartnerReferenceTable('pos_orders'),
            new PartnerReferenceTable('pos_customer_aliases', ['server_partner_id']),
        ];
    }
}
