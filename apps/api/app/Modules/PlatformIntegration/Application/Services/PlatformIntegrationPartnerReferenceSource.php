<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * Platform supplier-brand mappings.
 *
 * `platform_supplier_mappings.partner_id` (FK SET NULL) binds an external
 * platform supplier brand to a local supplier partner, and drives
 * auto-ordering. A mapping left pointing at a soft-deleted supplier keeps
 * routing purchase traffic to it.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class PlatformIntegrationPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('platform_supplier_mappings'),
        ];
    }
}
