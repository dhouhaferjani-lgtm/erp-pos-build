<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Repositories;

use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyMemberRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Eloquent implementation of LoyaltyMember repository
 */
final readonly class EloquentLoyaltyMemberRepository implements LoyaltyMemberRepositoryInterface
{
    /**
     * Find member by ID
     */
    public function findById(string $id): ?LoyaltyMember
    {
        return LoyaltyMember::find($id);
    }

    /**
     * Find member by phone number (normalized)
     */
    public function findByPhone(string $tenantId, string $phone): ?LoyaltyMember
    {
        $normalized = LoyaltyMember::normalizePhone($phone);

        return LoyaltyMember::where('tenant_id', $tenantId)
            ->where('phone', $normalized)
            ->first();
    }

    /**
     * Find member by customer ID
     */
    public function findByCustomerId(string $tenantId, string $customerId): ?LoyaltyMember
    {
        return LoyaltyMember::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->first();
    }

    /**
     * Get all members for a tenant
     *
     * @return Collection<int, LoyaltyMember>
     */
    public function findByTenant(string $tenantId): Collection
    {
        return LoyaltyMember::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Search members by name, phone, or email
     *
     * @return Collection<int, LoyaltyMember>
     */
    public function search(string $tenantId, string $query): Collection
    {
        $normalized = LoyaltyMember::normalizePhone($query);

        return LoyaltyMember::where('tenant_id', $tenantId)
            ->where(function ($q) use ($query, $normalized) {
                $q->where('phone', 'like', "%{$normalized}%")
                    ->orWhere('email', 'like', "%{$query}%")
                    ->orWhere('first_name', 'like', "%{$query}%")
                    ->orWhere('last_name', 'like', "%{$query}%");
            })
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
    }

    /**
     * Save member
     */
    public function save(LoyaltyMember $member): LoyaltyMember
    {
        // Normalize phone before saving
        if ($member->phone) {
            $member->phone = LoyaltyMember::normalizePhone($member->phone);
        }

        $member->save();

        return $member->fresh();
    }

    /**
     * Delete member
     */
    public function delete(string $id): bool
    {
        $member = $this->findById($id);

        if ($member === null) {
            return false;
        }

        return (bool) $member->delete();
    }
}
