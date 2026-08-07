<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Services;

use App\Shared\Partner\PartnerReferenceTable;
use App\Shared\Partner\TableBackedPartnerReferenceSource;

/**
 * Workshop jobs involving the partner.
 *
 * - `workshop_work_orders.customer_partner_id` (FK RESTRICT): the job's
 *   customer. The ticket's headline example — an Otospex partner with an
 *   OPEN work order used to soft-delete cleanly.
 * - `workshop_work_order_lines.core_deposit_partner_id` (FK SET NULL): the
 *   partner owed a core deposit on that line, a real payable.
 *
 * Both tables soft-delete, so closed-and-deleted history does not block.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class WorkshopPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable(
                'workshop_work_orders',
                ['customer_partner_id'],
                hasSoftDeletes: true,
            ),
            new PartnerReferenceTable(
                'workshop_work_order_lines',
                ['core_deposit_partner_id'],
                hasSoftDeletes: true,
            ),
        ];
    }
}
