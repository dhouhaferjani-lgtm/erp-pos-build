<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Shared\Partner\PartnerReferenceTable;
use App\Shared\Partner\TableBackedPartnerReferenceSource;

/**
 * Documents (quotes, orders, invoices, credit notes, delivery notes) still
 * billed to or received from the partner. `documents.partner_id` is a
 * NO ACTION foreign key, so a hard delete would fail loudly — but the
 * partner is SOFT-deleted, which never fires the FK, leaving the whole
 * commercial history pointing at an invisible partner.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class DocumentPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('documents', ['partner_id'], hasSoftDeletes: true),
        ];
    }
}
