<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Repositories;

use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use Illuminate\Support\Collection;

/**
 * Repository interface for LoyaltyMember
 */
interface LoyaltyMemberRepositoryInterface
{
    /**
     * Find member by ID
     */
    public function findById(string $id): ?LoyaltyMember;

    /**
     * Find member by phone number (normalized)
     */
    public function findByPhone(string $tenantId, string $phone): ?LoyaltyMember;

    /**
     * Find member by customer ID
     */
    public function findByCustomerId(string $tenantId, string $customerId): ?LoyaltyMember;

    /**
     * Get all members for a tenant
     *
     * @return Collection<int, LoyaltyMember>
     */
    public function findByTenant(string $tenantId): Collection;

    /**
     * Search members by name, phone, or email
     *
     * @return Collection<int, LoyaltyMember>
     */
    public function search(string $tenantId, string $query): Collection;

    /**
     * Save member
     */
    public function save(LoyaltyMember $member): LoyaltyMember;

    /**
     * Delete member
     */
    public function delete(string $id): bool;
}
