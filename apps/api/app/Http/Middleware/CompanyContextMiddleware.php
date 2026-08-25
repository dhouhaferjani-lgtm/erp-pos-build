<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
 *
 * ERROR CONTRACT (W2-1 / LEDGER C-13(iii)) — every rejection is a typed code
 * the SPA keys on, never an opaque failure:
 *
 * | code                    | status | meaning                                        |
 * |-------------------------|--------|------------------------------------------------|
 * | `INVALID_COMPANY_ID`    | 400    | the header is not a UUID — a junk client value  |
 * | `NO_COMPANY_ACCESS`     | 403    | the user is a member of no company at all       |
 * | `COMPANY_ACCESS_DENIED` | 403    | the requested company is not one of the user's  |
 *
 * `INVALID_COMPANY_ID` and `COMPANY_ACCESS_DENIED` both mean "your persisted
 * selection is stale/junk"; the web client resets the selection and re-bootstraps
 * on either (`apps/web/src/lib/api.ts` `handleCompanyScopeRejection`).
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
        $headerCompanyId = $this->headerCompanyId($request);

        // C-13(iii): the header is client-supplied. Anything that is not a UUID
        // must be rejected HERE — pushed into the `company_id` uuid predicate it
        // raises SQLSTATE 22P02 on PostgreSQL and the request 500s, which tells
        // the client nothing and looks like a server fault in monitoring.
        if ($headerCompanyId !== null && ! Str::isUuid($headerCompanyId)) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_COMPANY_ID',
                    'message' => 'The X-Company-Id header is not a valid company identifier.',
                ],
            ], 400);
        }

        // Priority 1: explicit header. Priority 2: the user's default company.
        $companyId = $headerCompanyId ?? $this->companyContext->getDefaultCompanyForUser($user);

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
     * The explicitly requested company id, or null when the header is absent
     * or empty (an empty header means "no explicit selection", not "junk").
     */
    private function headerCompanyId(Request $request): ?string
    {
        $headerCompanyId = $request->header('X-Company-Id');

        if (! is_string($headerCompanyId) || $headerCompanyId === '') {
            return null;
        }

        return $headerCompanyId;
    }
}
