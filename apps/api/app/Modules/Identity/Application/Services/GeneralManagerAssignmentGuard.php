<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\SystemRoleName;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\Exceptions\HttpResponseException;

final readonly class GeneralManagerAssignmentGuard
{
    /**
     * @param  list<string>  $effectiveRoleNames
     * @param  array<string>|null  $effectiveAllowedLocationIds
     */
    public function assertAssignable(User $actor, string $targetUserId, string $companyId, array $effectiveRoleNames, ?array $effectiveAllowedLocationIds): void
    {
        if (in_array(SystemRoleName::GeneralManager->value, $effectiveRoleNames, true)) {
            $this->assertEveryActiveMembershipIsUnrestricted($targetUserId, $companyId, $effectiveAllowedLocationIds);
        }
    }

    /**
     * @param  list<string>  $effectiveRoleNames
     * @param  array<string>|null  $effectiveAllowedLocationIds
     */
    public function assertLocationChangeAllowed(User $actor, string $targetUserId, string $companyId, array $effectiveRoleNames, ?array $effectiveAllowedLocationIds): void
    {
        $this->assertAssignable($actor, $targetUserId, $companyId, $effectiveRoleNames, $effectiveAllowedLocationIds);
    }

    /** @param array<string>|null $effectiveAllowedLocationIds */
    private function assertEveryActiveMembershipIsUnrestricted(string $targetUserId, string $companyId, ?array $effectiveAllowedLocationIds): void
    {
        $memberships = UserCompanyMembership::query()->where('user_id', $targetUserId)
            ->where('status', MembershipStatus::Active)->orderBy('company_id')->lockForUpdate()->get();
        $invalid = ! $memberships->contains('company_id', $companyId);
        foreach ($memberships as $membership) {
            $locations = $membership->company_id === $companyId ? $effectiveAllowedLocationIds : $membership->allowed_location_ids;
            $invalid = $invalid || $locations !== null;
        }
        if ($invalid) {
            throw new HttpResponseException(response()->json(['error' => [
                'code' => 'GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP',
                'message' => 'General managers require unrestricted active company memberships.',
            ]], 422));
        }
    }
}
