<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use Illuminate\Auth\Access\AuthorizationException;

final class OwnerReportScope
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * @param  list<string>|null  $requestedCompanyIds
     * @return list<string>
     *
     * @throws AuthorizationException
     */
    public function companyIds(?array $requestedCompanyIds, User $user): array
    {
        $rootCompanyId = $this->companyContext->requireCompanyId();

        if (! $this->companyContext->userHasAccessToCompany($user, $rootCompanyId)) {
            throw new AuthorizationException('User cannot access the selected company.');
        }

        $allowed = Company::query()
            ->where('id', $rootCompanyId)
            ->orWhere('parent_company_id', $rootCompanyId)
            ->pluck('id')
            ->map(fn (string $id): string => $id)
            ->values()
            ->all();
        $allowed = array_values($allowed);

        if ($requestedCompanyIds === null || $requestedCompanyIds === []) {
            return $allowed;
        }

        $requested = array_values(array_unique($requestedCompanyIds));
        if (array_diff($requested, $allowed) !== []) {
            throw new AuthorizationException('Requested company is outside the owner reporting scope.');
        }

        return $requested;
    }

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>|null  $requestedLocationIds
     * @return list<string>
     *
     * @throws AuthorizationException
     */
    public function locationIds(array $companyIds, ?array $requestedLocationIds, User $user): array
    {
        $rootCompanyId = $this->companyContext->requireCompanyId();
        $memberships = $this->membershipsByCompany($user, array_values(array_unique([...$companyIds, $rootCompanyId])));
        $rootMembership = $memberships[$rootCompanyId] ?? null;

        $allowed = [];
        $locations = Location::query()
            ->whereIn('company_id', $companyIds)
            ->get(['id', 'company_id']);

        foreach ($locations as $location) {
            $companyId = (string) $location->company_id;
            $membership = $memberships[$companyId] ?? $rootMembership;

            if ($membership === null) {
                continue;
            }

            $allowedLocationIds = $membership->allowed_location_ids;
            $locationId = (string) $location->id;

            if ($allowedLocationIds === null || in_array($locationId, $allowedLocationIds, true)) {
                $allowed[] = $locationId;
            }
        }

        if ($requestedLocationIds === null || $requestedLocationIds === []) {
            return $allowed;
        }

        $requested = array_values(array_unique($requestedLocationIds));
        if (array_diff($requested, $allowed) !== []) {
            throw new AuthorizationException('Requested location is outside the owner reporting scope.');
        }

        return $requested;
    }

    /**
     * @param  list<string>  $companyIds
     * @return array<string, UserCompanyMembership>
     */
    private function membershipsByCompany(User $user, array $companyIds): array
    {
        $memberships = [];

        UserCompanyMembership::query()
            ->where('user_id', $user->id)
            ->whereIn('company_id', $companyIds)
            ->where('status', 'active')
            ->get()
            ->each(function (UserCompanyMembership $membership) use (&$memberships): void {
                $memberships[(string) $membership->company_id] = $membership;
            });

        return $memberships;
    }
}
