<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Shared\Application\Partner\PartnerReferenceColumn;
use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;

/**
 * Loyalty enrolment for the partner.
 *
 * `loyalty_members.customer_id` (FK SET NULL, soft-deletes). This is not
 * mere configuration: a member row carries a POINTS BALANCE, which is an
 * outstanding liability towards the customer in exactly the same sense a
 * voucher is.
 *
 * TWO OWNERSHIP CHANNELS. Loyalty records partner ownership either through
 * `customer_id` OR through the polymorphic anchor
 * (`loyaltyable_type='partner'` + `loyaltyable_id`), and the module's own
 * code treats the two as equivalent — see `MemberResolver::resolve()` and
 * `LoyaltyPartnerController::isBoundToPartner()`, both of which OR them.
 * `LoyaltyMemberController::store` accepts an anchor-only member, so
 * `customer_id` can be NULL on a member that is unambiguously this
 * partner's. Counting only `customer_id` let exactly those members —
 * points balance included — be orphaned by a soft delete.
 *
 * Registered on the `PartnerReferenceSource` container tag by this module's
 * service provider; consumed by the partner delete guard
 * (`PartnerReferenceCounter`). See the contract for the BUG-007
 * connection-timing rule the base class enforces.
 */
final class LoyaltyPartnerReferenceSource extends TableBackedPartnerReferenceSource
{
    public function tables(): array
    {
        return [
            new PartnerReferenceTable(
                'loyalty_members',
                [
                    'customer_id',
                    // The polymorphic anchor. `loyaltyable_id` is only a
                    // partner reference under `loyaltyable_type='partner'` —
                    // without the discriminator this would also match a
                    // member anchored to a contact that shares the uuid.
                    new PartnerReferenceColumn('loyaltyable_id', ['loyaltyable_type' => 'partner']),
                ],
                hasSoftDeletes: true,
            ),
        ];
    }
}
