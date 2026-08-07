<?php

declare(strict_types=1);

namespace App\Modules\Company\Services;

use App\Modules\Company\Domain\Location;

/** Shared active-location boundary used by all financial read surfaces. */
final class LocationScopeBoundary
{
    /** @return list<string> */
    public function activeLocationIds(string $companyId): array
    {
        return array_values(Location::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * Every location the company has, active or not. Used to detect that a
     * company has a DEACTIVATED location at all (ticket 2026-08-06 (a)):
     * {@see activeLocationIds()} alone can't tell "no locations exist beyond
     * the active set" apart from "a deactivated one exists and is being
     * silently excluded from the active count".
     *
     * @return list<string>
     */
    public function allLocationIds(string $companyId): array
    {
        return array_values(Location::query()
            ->where('company_id', $companyId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * A principal is unrestricted only when their effective grant covers
     * EVERY company location, active or not (ticket
     * 2026-08-06-l3-cash-scope-residuals.md (a), P1; merge-gate F-1/F-3,
     * 2026-08-07). Comparing against {@see activeLocationIds()} instead used
     * to make a grant that merely covered today's active set collapse to
     * "unrestricted" the moment any OTHER location went inactive — the
     * caller then applies no location predicate at all, which (a) leaks the
     * deactivated location's rows to a principal who was never granted it,
     * and (b) fails OPEN entirely once every location is inactive
     * (`activeLocationIds()` returns `[]`, and `[]` reads as "unrestricted"
     * everywhere downstream). Comparing against every location closes both:
     * a genuinely unrestricted grant (e.g. an admin's null membership, which
     * resolves to every company location regardless of active state — see
     * `LocationScopeResolver::allCompanyLocationIds()`) still collapses to
     * "no predicate", keeping NULL/unattributed rows and every location's
     * rows visible; any grant short of that — including one that happens to
     * equal today's active set — is correctly restricted, and the caller
     * applies it as an explicit `location_id IN (...)` filter that a
     * deactivated, non-granted location can never pass.
     *
     * @param  list<string>  $effectiveLocationIds
     */
    public function isUnrestricted(string $companyId, array $effectiveLocationIds): bool
    {
        return count(array_diff($this->allLocationIds($companyId), $effectiveLocationIds)) === 0;
    }
}
