<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * Vouchers held by, or issued to, the partner.
 *
 * `vouchers` has NO foreign key on either partner column, so the database
 * offers no protection whatsoever here. An outstanding voucher is a
 * liability of the company towards this partner; letting the partner
 * disappear leaves an instrument nobody can attribute.
 *
 * Both columns are OR'd into ONE count: a voucher issued to and still held
 * by the same partner is one row, not two.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class VoucherPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable(
                'vouchers',
                ['partner_id', 'issued_to_partner_id'],
                hasSoftDeletes: true,
            ),
        ];
    }
}
