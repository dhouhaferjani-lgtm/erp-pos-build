<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * Loyalty enrolment for the partner.
 *
 * `loyalty_members.customer_id` (FK SET NULL, soft-deletes). This is not
 * mere configuration: a member row carries a POINTS BALANCE, which is an
 * outstanding liability towards the customer in exactly the same sense a
 * voucher is.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class LoyaltyPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('loyalty_members', ['customer_id'], hasSoftDeletes: true),
        ];
    }
}
