<?php

declare(strict_types=1);

namespace App\Modules\Company\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\Auth;

/**
 * Manages the current active location for the request/session.
 *
 * Similar to CompanyContext but scoped per-operation rather than system-wide.
 * Provides location resolution with intelligent fallback chain:
 * 1. Explicit location_id parameter
 * 2. Current active location (from context)
 * 3. Default location for company
 * 4. null (for central invoicing scenarios)
 *
 * Also provides location access validation based on user company membership permissions.
 * Note: Phase 3 middleware will be the primary enforcement point for most HTTP requests.
 */
class LocationContext
{
    private ?string $locationId = null;

    /**
     * Set active location for this request.
     *
     * Called by middleware or controller to establish location scope.
     */
    public function setLocationId(?string $locationId): void
    {
        $this->locationId = $locationId;
    }

    /**
     * Get current active location ID.
     *
     * Returns null if no location set (e.g., central invoicing scenario).
     */
    public function getLocationId(): ?string
    {
        return $this->locationId;
    }

    /**
     * Get current active location ID or throw exception.
     *
     * Use when operation absolutely requires location context.
     *
     * @throws \RuntimeException
     */
    public function requireLocationId(): string
    {
        if ($this->locationId === null) {
            throw new \RuntimeException(
                'Location context is required for this operation but no location is set. '.
                'Please specify a location_id or set an active location.'
            );
        }

        return $this->locationId;
    }

    /**
     * Get current active location model or throw exception.
     *
     * @throws \RuntimeException
     */
    public function requireLocation(): Location
    {
        $locationId = $this->requireLocationId();

        $location = Location::find($locationId);

        if (! $location) {
            throw new \RuntimeException(
                "Location with ID {$locationId} not found."
            );
        }

        return $location;
    }

    /**
     * Get default location for current company.
     *
     * Queries Location::where('company_id', ...)
     *                  ->where('is_default', true)
     *                  ->first()
     *
     * Falls back to first active location if no default.
     *
     * @param  string  $companyId  Company UUID
     */
    public function getDefaultLocation(string $companyId): ?Location
    {
        // Try to find default location
        $defaultLocation = Location::where('company_id', $companyId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($defaultLocation) {
            return $defaultLocation;
        }

        // Fallback to first active location
        return Location::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Resolve location ID with fallback chain:
     * 1. Explicit location_id parameter (if provided)
     * 2. Current active location (from context)
     * 3. Default location for company
     * 4. null (if central invoicing or no locations exist)
     *
     * @param  string|null  $explicitLocationId  Optional explicit location ID
     * @param  string|null  $companyId  Company UUID for default location lookup
     */
    public function resolveLocationId(?string $explicitLocationId = null, ?string $companyId = null): ?string
    {
        // Priority 1: Explicit location ID parameter
        if ($explicitLocationId !== null) {
            return $explicitLocationId;
        }

        // Priority 2: Current active location from context
        if ($this->locationId !== null) {
            return $this->locationId;
        }

        // Priority 3: Default location for company
        if ($companyId !== null) {
            $defaultLocation = $this->getDefaultLocation($companyId);
            if ($defaultLocation) {
                return $defaultLocation->id;
            }
        }

        // Priority 4: null (central invoicing or no locations)
        return null;
    }

    /**
     * Clear location context (for testing or session end).
     */
    public function clear(): void
    {
        $this->locationId = null;
    }

    /**
     * Get current user's company membership for location access checks.
     *
     * @param  string  $companyId  Company UUID
     * @param  User|null  $user  User (defaults to authenticated user)
     * @return UserCompanyMembership|null Membership or null if not found
     */
    public function getCurrentMembership(string $companyId, ?User $user = null): ?UserCompanyMembership
    {
        $user = $user ?? Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        return UserCompanyMembership::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Check if user can access a specific location.
     *
     * Returns true if:
     * - User's membership has null allowed_location_ids (access to all locations)
     * - User's membership includes this location_id in allowed_location_ids
     *
     * Returns false if:
     * - No membership found
     * - Membership restricts locations and this location is not included
     *
     * @param  string  $locationId  Location UUID to check
     * @param  string  $companyId  Company UUID
     * @param  User|null  $user  User (defaults to authenticated user)
     */
    public function canAccessLocation(string $locationId, string $companyId, ?User $user = null): bool
    {
        $membership = $this->getCurrentMembership($companyId, $user);

        if ($membership === null) {
            return false;
        }

        // Null means all locations accessible
        if ($membership->allowed_location_ids === null) {
            return true;
        }

        // Check if location is in allowed list
        return in_array($locationId, $membership->allowed_location_ids, true);
    }

    /**
     * Validate that user can access location or throw exception.
     *
     * This provides defense-in-depth validation for service layer.
     * Primary enforcement should still be via Phase 3 middleware at HTTP layer.
     *
     * @param  string  $locationId  Location UUID to validate
     * @param  string  $companyId  Company UUID
     * @param  User|null  $user  User (defaults to authenticated user)
     *
     * @throws \RuntimeException If user cannot access location
     */
    public function validateLocationAccess(string $locationId, string $companyId, ?User $user = null): void
    {
        if (! $this->canAccessLocation($locationId, $companyId, $user)) {
            $user = $user ?? Auth::user();
            $userId = $user instanceof User ? $user->id : 'unknown';

            throw new \RuntimeException(
                "User {$userId} does not have access to location {$locationId} in company {$companyId}. ".
                "Please check user's allowed_location_ids in company membership."
            );
        }
    }
}
