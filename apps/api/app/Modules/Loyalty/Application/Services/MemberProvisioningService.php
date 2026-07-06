<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Services;

use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;

/**
 * Find-or-create a loyalty member by the unique (tenant, phone) key. Extracted
 * from PosLoyaltyBalanceService so the POS attach-flow and the partner-record
 * enrollment surface share one provisioning path (identical dedupe / restore /
 * re-point semantics). Idempotent: re-provisioning never duplicates a member.
 */
final readonly class MemberProvisioningService
{
    public function findOrCreateMember(
        string $tenantId,
        ?string $partnerId,
        ?string $contactId,
        string $phone,
        ?string $name,
    ): LoyaltyMember {
        $normalized = LoyaltyMember::normalizePhone($phone);

        // Dedupe by the unique key (tenant, phone): reuse an existing member if present.
        // The unique index is non-partial and the model soft-deletes, so a soft-deleted
        // row still occupies the index — query withTrashed and restore it instead of
        // creating (which would 500 on the unique violation). (Codex N2)
        $existing = LoyaltyMember::withTrashed()
            ->where('tenant_id', $tenantId)->where('phone', $normalized)->first();
        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            [$type, $id] = $contactId !== null ? ['contact', $contactId] : ['partner', $partnerId];
            $existing->loyaltyable_type = $type;
            $existing->loyaltyable_id = $id;
            $existing->customer_id = $partnerId;
            $existing->save();

            return $existing;
        }

        [$type, $id] = $contactId !== null ? ['contact', $contactId] : ['partner', $partnerId];

        return LoyaltyMember::create([
            'tenant_id' => $tenantId,
            'loyaltyable_type' => $type,
            'loyaltyable_id' => $id,
            'customer_id' => $partnerId,
            'phone' => $normalized,
            'first_name' => $name,
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);
    }
}
