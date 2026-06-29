<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Resolvers;

use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;

/** Resolves a loyalty member by polymorphic contact/partner, tenant-scoped. */
final readonly class MemberResolver
{
    public function resolveByContactOrPartner(string $tenantId, ?string $contactId, ?string $partnerId): ?LoyaltyMember
    {
        $member = null;

        if ($contactId !== null) {
            $member = LoyaltyMember::query()
                ->where('loyaltyable_type', 'contact')
                ->where('loyaltyable_id', $contactId)
                ->where('tenant_id', $tenantId)
                ->first();
        }

        if ($member === null && $partnerId !== null) {
            $member = LoyaltyMember::query()
                ->where(function ($q) use ($partnerId) {
                    $q->where(function ($q2) use ($partnerId) {
                        $q2->where('loyaltyable_type', 'partner')
                            ->where('loyaltyable_id', $partnerId);
                    })->orWhere('customer_id', $partnerId);
                })
                ->where('tenant_id', $tenantId)
                ->first();
        }

        return $member;
    }
}
