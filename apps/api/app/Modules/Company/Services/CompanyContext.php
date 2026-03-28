<?php

declare(strict_types=1);

namespace App\Modules\Company\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;

/**
 * Service for managing the current company context.
 *
 * In a multi-company environment, this service helps determine
 * which company the current request is operating on.
 */
class CompanyContext
{
    private ?string $currentCompanyId = null;

    /**
     * Set the current company ID.
     */
    public function setCompanyId(string $companyId): void
    {
        $this->currentCompanyId = $companyId;
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
     * Returns the first company the user is a member of.
     */
    public function getDefaultCompanyForUser(User $user): ?string
    {
        $membership = UserCompanyMembership::where('user_id', $user->id)
            ->first();

        return $membership?->company_id;
    }

    /**
     * Check if a user has access to a specific company.
     */
    public function userHasAccessToCompany(User $user, string $companyId): bool
    {
        return UserCompanyMembership::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->exists();
    }

    /**
     * Clear the current company context.
     */
    public function clear(): void
    {
        $this->currentCompanyId = null;
    }
}
