<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * Marketplace buyer/seller identity mappings.
 *
 * `buyer_seller_mappings` links a marketplace seller to the local partner
 * records on both sides (`buyer_partner_id`, `seller_partner_id`, both FK
 * SET NULL). While a mapping survives, inbound marketplace traffic keeps
 * resolving to the soft-deleted partner.
 *
 * Both columns are OR'd into ONE count — a self-mapping is one row.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class MarketplacePartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable(
                'buyer_seller_mappings',
                ['buyer_partner_id', 'seller_partner_id'],
            ),
        ];
    }
}
