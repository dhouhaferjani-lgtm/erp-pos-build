<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * Withholding-tax records naming the partner.
 *
 * - `withholding_certificates.partner_id` (FK RESTRICT, NOT NULL): a filed
 *   or issuable fiscal certificate. These are declared to the tax
 *   administration; the counterparty must stay resolvable.
 * - `sales_withholding_tracking.customer_id` (FK NO ACTION): the expected
 *   receivable behind a certificate that has not come back yet.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class TaxationPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('withholding_certificates'),
            new PartnerReferenceTable('sales_withholding_tracking', ['customer_id']),
        ];
    }
}
