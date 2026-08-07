<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * POS rows bound to the partner.
 *
 * - `pos_receipts` (FK SET NULL): fiscal receipts, immutable by design
 *   (NF525) and never deleted.
 * - `pos_orders` (FK NO ACTION): open/held orders that have not yet become
 *   a receipt — an order left on a table would still be tendered against
 *   the deleted customer.
 * - `pos_customer_aliases` (FK CASCADE on `server_partner_id`): the device
 *   `client_customer_uuid` -> server partner identity map. This one is
 *   CASCADE, i.e. schema-owned by the partner, but it is NOT descriptive
 *   configuration: while it survives, a POS device keeps resolving its
 *   local customer to the soft-deleted partner and keeps attaching new
 *   receipts to it. That is the exact orphaning this guard exists to stop.
 *
 * THE SEALED RECEIPT FAMILY — `pos_deposit_receipts`,
 * `pos_account_charge_receipts`, `pos_account_payment_receipts`, all on
 * `customer_id`. These are fiscal projections of DEPOSIT_RECEIPT /
 * ACCOUNT_CHARGE / ACCOUNT_PAYMENT events and they are the ONLY server-side
 * trace those events leave by default: none of the three writes a
 * `pos_receipts` or `documents` row, and their Treasury counterparts are
 * contingent on the Treasury module being active AND its bridge having
 * run. The failure this closes (R2-S treasury gate): a sealed
 * DEPOSIT_RECEIPT whose bridge row is still pending produces no `payments`
 * and no `journal_lines`, so the guard counted zero, returned 204, and the
 * customer's advance-payment liability was stranded — `/partners/{id}/
 * deposits` can never surface it again once the partner is soft-deleted.
 *
 * CAVEAT — `customer_id` here is a VARCHAR, not a uuid foreign key, and on
 * a `pending_create` device row it can legitimately hold a DEVICE-LOCAL
 * uuid rather than a server partner id. Such a row will not match this
 * partner and will not block on its own. That is correct rather than a
 * hole: the device-local id is bound to the server partner through
 * `pos_customer_aliases`, which is counted immediately above, so the
 * partner is still protected — via the alias rather than via the receipt.
 * The two tables cover each other, and neither alone is sufficient.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class PosPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable('pos_receipts'),
            new PartnerReferenceTable('pos_orders'),
            new PartnerReferenceTable('pos_customer_aliases', ['server_partner_id']),
            new PartnerReferenceTable('pos_deposit_receipts', ['customer_id']),
            new PartnerReferenceTable('pos_account_charge_receipts', ['customer_id']),
            new PartnerReferenceTable('pos_account_payment_receipts', ['customer_id']),
        ];
    }
}
