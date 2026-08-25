<?php

declare(strict_types=1);

namespace App\Modules\Company\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use Illuminate\Support\Str;

/**
 * Service for managing the current company context.
 *
 * In a multi-company environment, this service helps determine
 * which company the current request is operating on.
 */
class CompanyContext
{
    private ?string $currentCompanyId = null;

    private ?string $currentTenantId = null;

    /**
     * Set the current company ID.
     */
    public function setCompanyId(string $companyId): void
    {
        $this->currentCompanyId = $companyId;
        $this->currentTenantId = null;
    }

    /**
     * Get the current company ID.
     */
    public function getCompanyId(): ?string
    {
        return $this->currentCompanyId;
    }

    /**
     * Get the current company ID or throw if not set.
     *
     * @throws \RuntimeException If no company context is set
     */
    public function requireCompanyId(): string
    {
        if ($this->currentCompanyId === null) {
            throw new \RuntimeException('No company context set. Ensure CompanyContextMiddleware is applied.');
        }

        return $this->currentCompanyId;
    }

    /**
     * Get the current tenant ID (parent of the bound company), looking it up
     * once per request and caching. Used by route-anchored reads that need
     * BOTH tenant_id and company_id predicates per the tenant-isolation
     * cluster invariant.
     *
     * @throws \RuntimeException If no company context is set or the company
     *                           cannot be resolved.
     */
    public function requireTenantId(): string
    {
        if ($this->currentTenantId !== null) {
            return $this->currentTenantId;
        }

        $this->currentTenantId = $this->requireCompany()->tenant_id;

        return $this->currentTenantId;
    }

    /**
     * Check if a company context is set.
     */
    public function hasCompany(): bool
    {
        return $this->currentCompanyId !== null;
    }

    /**
     * Get the current company model.
     */
    public function getCompany(): ?Company
    {
        if ($this->currentCompanyId === null) {
            return null;
        }

        return Company::find($this->currentCompanyId);
    }

    /**
     * Get the current company model or throw if not set.
     *
     * @throws \RuntimeException If no company context is set
     */
    public function requireCompany(): Company
    {
        $companyId = $this->requireCompanyId();
        $company = Company::with('tenant')->find($companyId);

        if ($company === null) {
            throw new \RuntimeException("Company not found with ID: {$companyId}");
        }

        return $company;
    }

    /**
     * Get the default company ID for a user.
     * Returns the first company the user is an ACTIVE member of.
     *
     * FU-2a: a suspended/revoked membership must not grant a default company —
     * `getDefaultCompanyForUser` feeds `CompanyContextMiddleware` and an inactive
     * membership passing here would let a deactivated user keep operating.
     *
     * Selection is DETERMINISTIC: prefer the membership flagged `is_primary`,
     * then fall back to the oldest membership (stable `created_at`, tie-broken by
     * `id`). A bare `->first()` with no ordering returned an arbitrary row that
     * could change between requests, silently switching the user's active
     * company — one of the "scope switches on its own" root causes.
     */
    public function getDefaultCompanyForUser(User $user): ?string
    {
        $membership = UserCompanyMembership::where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return $membership?->company_id;
    }

    /**
     * Check if a user has an ACTIVE membership in a specific company.
     *
     * FU-2a: this gates every company-scoped route via `CompanyContextMiddleware`.
     * It must require an ACTIVE membership, not mere existence — otherwise a
     * suspended/revoked member who still holds a valid token keeps passing
     * company context (the root of the FU-2 privilege-escalation finding).
     *
     * LEDGER C-13(iii): `$companyId` reaches here from client-supplied input
     * (the `X-Company-Id` header via `CompanyContextMiddleware`, a request
     * parameter via `OwnerReportScope`). `company_id` is a PostgreSQL `uuid`
     * column, so a non-uuid literal raises SQLSTATE 22P02 and 500s the request
     * instead of denying access. A malformed id can never match a membership,
     * so it is a plain "no access" — fail closed, never throw.
     */
    public function userHasAccessToCompany(User $user, string $companyId): bool
    {
        if (! Str::isUuid($companyId)) {
            return false;
        }

        return UserCompanyMembership::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }

    /**
     * Clear the current company context.
     */
    public function clear(): void
    {
        $this->currentCompanyId = null;
        $this->currentTenantId = null;
    }
}
