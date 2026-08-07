<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Services;

use App\Shared\Partner\PartnerReferenceTable;
use App\Shared\Partner\TableBackedPartnerReferenceSource;

/**
 * Appointments booked for the partner.
 *
 * `scheduling_appointments.customer_partner_id` is SET NULL, so a hard
 * delete would quietly anonymise a future booking rather than refuse it.
 * A soft delete does not even do that: the appointment keeps naming a
 * customer who no longer appears anywhere in the UI.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class SchedulingPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable(
                'scheduling_appointments',
                ['customer_partner_id'],
                hasSoftDeletes: true,
            ),
        ];
    }
}
