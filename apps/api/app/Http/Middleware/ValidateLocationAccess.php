<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Identity\Domain\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to validate that the authenticated user has access to requested location(s).
 *
 * This middleware extracts location_id from:
 * 1. Request input (POST/PUT/PATCH bodies)
 * 2. Route parameters (URL segments)
 * 3. Query parameters (GET requests)
 *
 * It then validates that the user's company membership allows access to those locations.
 *
 * Usage in routes:
 * Route::post('/stock/receive', [StockMovementController::class, 'receive'])
 *     ->middleware('auth:sanctum', 'validate.location.access');
 *
 * Special handling:
 * - from_location_id and to_location_id (transfers) - validates BOTH
 * - location_id as array (batch operations) - validates ALL
 * - Skips validation if no location IDs present
 * - Returns 403 if user cannot access location
 */
class ValidateLocationAccess
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user instanceof User) {
            // Not authenticated - let auth middleware handle
            return $next($request);
        }

        // Get current company context
        if (! $this->companyContext->hasCompany()) {
            // No company context set - let other middleware handle
            return $next($request);
        }

        $companyId = $this->companyContext->requireCompanyId();

        // Collect all location IDs to validate
        $locationIds = $this->collectLocationIds($request);

        // If no locations specified, allow request to proceed
        if (empty($locationIds)) {
            return $next($request);
        }

        // Validate each location
        foreach ($locationIds as $locationId) {
            if (! $this->locationContext->canAccessLocation($locationId, $companyId, $user)) {
                return response()->json([
                    'error' => [
                        'code' => 'LOCATION_ACCESS_DENIED',
                        'message' => "You do not have permission to access location {$locationId}.",
                        'details' => [
                            'location_id' => $locationId,
                            'user_id' => $user->id,
                        ],
                    ],
                ], 403);
            }
        }

        return $next($request);
    }

    /**
     * Collect all location IDs from the request.
     *
     * @return array<int, string> Array of location IDs (UUIDs)
     */
    private function collectLocationIds(Request $request): array
    {
        $locationIds = [];

        // Check request input (POST/PUT/PATCH bodies)
        if ($request->has('location_id')) {
            $value = $request->input('location_id');
            if (is_array($value)) {
                $locationIds = array_merge($locationIds, $value);
            } elseif (is_string($value)) {
                $locationIds[] = $value;
            }
        }

        // Check for transfer-specific fields
        if ($request->has('from_location_id')) {
            $locationIds[] = $request->input('from_location_id');
        }
        if ($request->has('to_location_id')) {
            $locationIds[] = $request->input('to_location_id');
        }

        // Check route parameters
        if ($request->route('location_id')) {
            $locationIds[] = $request->route('location_id');
        }

        // Check query parameters
        if ($request->query('location_id')) {
            $value = $request->query('location_id');
            if (is_array($value)) {
                $locationIds = array_merge($locationIds, $value);
            } elseif (is_string($value)) {
                $locationIds[] = $value;
            }
        }

        // Check for document lines with location_id
        if ($request->has('lines') && is_array($request->input('lines'))) {
            foreach ($request->input('lines') as $line) {
                if (isset($line['location_id']) && is_string($line['location_id'])) {
                    $locationIds[] = $line['location_id'];
                }
            }
        }

        // Remove duplicates and nulls
        return array_values(array_unique(array_filter($locationIds, fn ($id) => is_string($id) && $id !== '')));
    }
}
