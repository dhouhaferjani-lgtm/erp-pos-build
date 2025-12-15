<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to set the company context for multi-company requests.
 *
 * Priority order:
 * 1. X-Company-Id header (explicit company selection)
 * 2. User's default company (first company membership)
 *
 * The middleware validates that the authenticated user has access to the
 * requested company before setting the context.
 */
final class CompanyContextMiddleware
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip company context for admin routes entirely
        // Admin routes operate on the central database and don't need company context
        if ($this->isAdminRoute($request)) {
            return $next($request);
        }

        /** @var Authenticatable|null $authenticatedUser */
        $authenticatedUser = $request->user();

        if ($authenticatedUser === null) {
            // Not authenticated, let auth middleware handle it
            return $next($request);
        }

        // Skip company context for non-User types (e.g., SuperAdmin)
        if (! $authenticatedUser instanceof User) {
            return $next($request);
        }

        $user = $authenticatedUser;
        $companyId = $this->resolveCompanyId($request, $user);

        if ($companyId === null) {
            return response()->json([
                'error' => [
                    'code' => 'NO_COMPANY_ACCESS',
                    'message' => 'User is not a member of any company.',
                ],
            ], 403);
        }

        // Validate user has access to this company
        if (! $this->companyContext->userHasAccessToCompany($user, $companyId)) {
            return response()->json([
                'error' => [
                    'code' => 'COMPANY_ACCESS_DENIED',
                    'message' => 'User does not have access to the requested company.',
                ],
            ], 403);
        }

        // Set the company context
        $this->companyContext->setCompanyId($companyId);

        /** @var Response $response */
        $response = $next($request);

        // Add company context to response headers for debugging
        $response->headers->set('X-Company-Id', $companyId);

        return $response;
    }

    /**
     * Check if the request is for an admin route.
     */
    private function isAdminRoute(Request $request): bool
    {
        $path = $request->path();

        // Admin routes start with api/v1/admin or v1/admin
        return str_starts_with($path, 'api/v1/admin') || str_starts_with($path, 'v1/admin');
    }

    /**
     * Resolve the company ID from request or user's default.
     */
    private function resolveCompanyId(Request $request, User $user): ?string
    {
        // Priority 1: Explicit header
        $headerCompanyId = $request->header('X-Company-Id');
        if ($headerCompanyId !== null && $headerCompanyId !== '') {
            return $headerCompanyId;
        }

        // Priority 2: User's default company
        return $this->companyContext->getDefaultCompanyForUser($user);
    }
}
