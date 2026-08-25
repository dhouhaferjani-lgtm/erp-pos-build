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
    /**
     * Route names of the company-bootstrap endpoints (see handle()).
     *
     * `auth.me` sits in the `web` group and never reaches this middleware today;
     * it is listed so the exemption survives a future move into the `api` group.
     * `AuthController::me` does not consult CompanyContext either.
     *
     * @var list<string>
     */
    private const BOOTSTRAP_ROUTE_NAMES = ['user.companies', 'auth.me'];

    /**
     * Path fallback for the same endpoints, `api/` prefix already stripped.
     *
     * @var list<string>
     */
    private const BOOTSTRAP_ROUTE_PATHS = ['v1/user/companies', 'v1/auth/me'];

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

        // Skip company RESOLUTION entirely for the bootstrap routes (W2-1 / gate
        // r1 item 5). These are the calls that TELL a client which companies it
        // may use, so they must never depend on what it currently believes: a
        // client holding a stale/foreign/junk company id would be denied on the
        // one request that could have corrected it, and the deadlock could not
        // self-heal. That is the W2-1 failure, and it is still live in the POS
        // client (apps/pos/src/lib/api.ts sends X-Company-Id on every request,
        // and its own stale-company recovery depends on /user/companies
        // succeeding).
        //
        // Ignoring only the HEADER would not be enough: a user with no ACTIVE
        // membership would still get 403 NO_COMPANY_ACCESS — precisely the state
        // the recovery screen is in. So resolution is skipped wholesale.
        //
        // Provably safe: UserController::companies reads only $user->id and
        // getMeta only X-Request-ID — neither consults CompanyContext.
        if ($this->isCompanyBootstrapRoute($request)) {
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
     * Routes that must resolve NO company context.
     *
     * Matched by route NAME first (stable across prefix changes) with a path
     * fallback for the same endpoints, so an unnamed or unmatched route cannot
     * silently lose the exemption.
     */
    private function isCompanyBootstrapRoute(Request $request): bool
    {
        $routeName = $request->route() !== null ? $request->route()->getName() : null;

        if ($routeName !== null && in_array($routeName, self::BOOTSTRAP_ROUTE_NAMES, true)) {
            return true;
        }

        $path = trim($request->path(), '/');
        $path = preg_replace('#^api/#', '', $path) ?? $path;

        return in_array($path, self::BOOTSTRAP_ROUTE_PATHS, true);
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
