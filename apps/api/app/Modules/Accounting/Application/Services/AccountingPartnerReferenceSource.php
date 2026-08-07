<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * General-ledger postings analysed to the partner.
 *
 * `journal_lines.partner_id` is a RESTRICT foreign key — the schema already
 * says these must never dangle. A soft delete bypasses that guarantee, and
 * a GL line whose auxiliary account resolves to nothing breaks the partner
 * ledger / aged-balance reports.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class AccountingPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('journal_lines'),
        ];
    }
}
