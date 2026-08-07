<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Application\Services;

use App\Shared\Partner\PartnerReferenceTable;
use App\Shared\Partner\TableBackedPartnerReferenceSource;

/**
 * Coupon redemptions attributed to the partner.
 *
 * `coupon_usages.partner_id` has NO foreign key, and the
 * `(partner_id, coupon_id)` index exists precisely because per-partner
 * redemption limits are enforced from this table.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class CouponPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('coupon_usages'),
        ];
    }
}
