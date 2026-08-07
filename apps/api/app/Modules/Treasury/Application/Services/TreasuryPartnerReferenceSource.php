<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Shared\Partner\PartnerReferenceTable;
use App\Shared\Partner\TableBackedPartnerReferenceSource;

/**
 * Treasury rows that carry the partner's money.
 *
 * - `payments` (FK RESTRICT): settled or pending cash movements; these are
 *   what make up the partner's open balance.
 * - `payment_instruments` (NO foreign key at all): cheques and drafts drawn
 *   by or on the partner. Nothing at the database level protects these, so
 *   a lingering instrument would be presentable against a partner that no
 *   longer exists in any list.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class TreasuryPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('payments'),
            new PartnerReferenceTable('payment_instruments'),
        ];
    }
}
