<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Application\Services;

use App\Shared\Partner\PartnerReferenceTable;
use App\Shared\Partner\TableBackedPartnerReferenceSource;

/**
 * Promotion redemptions attributed to the partner.
 *
 * `promotion_usages.partner_id` has NO foreign key. The rows are the audit
 * trail behind a discount that was actually granted, and per-partner usage
 * limits are evaluated against them.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class PromotionPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('promotion_usages'),
        ];
    }
}
