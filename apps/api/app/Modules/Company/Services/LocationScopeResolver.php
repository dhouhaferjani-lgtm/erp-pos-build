<?php

declare(strict_types=1);

namespace App\Modules\Company\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * HTTP-only read-scope resolver for location_ids[] query params (spec §1).
 * Generalizes OwnerReportScope but: (a) single-company (no parent expansion),
 * (b) optional bypass permission (e.g. replenishment.process → all shops).
 * Requires a bound CompanyContext — backfills/migrations/queued jobs must
 * derive location directly from data, never via this resolver (review A10).
 */
final class LocationScopeResolver
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
    ) {}

    /**
     * @param  list<string>  $requestedIds
     * @return list<string>
     *
     * @throws AuthorizationException
     */
    public function resolve(User $user, array $requestedIds = [], ?string $bypassPermission = null): array
    {
        $companyId = $this->companyContext->requireCompanyId();
        $effectiveAllowed = $this->effectiveAllowedIds($user, $companyId, $bypassPermission);
        $requested = array_values(array_unique($requestedIds));

        if ($requested === []) {
            return $effectiveAllowed;
        }

        if (array_diff($requested, $effectiveAllowed) !== []) {
            throw new AuthorizationException('Requested location is outside your allowed scope.');
        }

        return $requested;
    }

    /**
     * @return list<string>
     */
    private function effectiveAllowedIds(User $user, string $companyId, ?string $bypassPermission): array
    {
        if ($bypassPermission !== null && $user->can($bypassPermission)) {
            return $this->allCompanyLocationIds($companyId);
        }

        $membershipAllowed = $this->locationContext->getAllowedLocationIds($companyId, $user);

        if ($membershipAllowed === null) {
            return $this->allCompanyLocationIds($companyId);
        }

        return array_values(array_intersect(
            $membershipAllowed,
            $this->allCompanyLocationIds($companyId),
        ));
    }

    /**
     * @return list<string>
     */
    private function allCompanyLocationIds(string $companyId): array
    {
        return array_values(Location::query()
            ->where('company_id', $companyId)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all());
    }
}
