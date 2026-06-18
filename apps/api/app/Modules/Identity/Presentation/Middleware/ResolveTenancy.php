<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Middleware;

use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use App\Modules\Tenant\Application\Services\TenancyResolver;
use App\Modules\Tenant\Application\Services\TenantLinkSigner;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request-time PRE-AUTH tenancy resolver (topology §9.4).
 *
 * There is no request-time tenancy layer in the app today; this middleware adds
 * it. It runs BEFORE `auth:sanctum` (it is registered on the `api` and Identity
 * `web` groups, which expand before route-level `auth:sanctum`) and binds the
 * tenant so that, post-flip, `auth:sanctum` resolves the `tokenable` User in
 * the tenant DB. It is the pre-auth counterpart to {@see EnforceTokenTenantClaim}
 * (the post-auth guard, which we keep).
 *
 * Two tenant sources (one middleware, two branches), plus a signed-link branch
 * for the tenant-qualified pre-auth flows (verify-email / reset-password):
 *
 *   1. Bearer token (POS / mobile / API): read the `tenant:<uuid>` ability that
 *      AuthController already mints on every token. (Phase 0b: once the central
 *      connection lands, the PAT row must be read from central via
 *      CentralPersonalAccessToken — see the deferred test + status doc. Today
 *      personal_access_tokens lives on the shared connection, so findToken works.)
 *   2. Web SPA cookie: AuthController::login stamps `tenant_id` into the session;
 *      this branch reads it back.
 *   3. Signed link param (`tenant`): tenant-qualified verify-email / reset links
 *      carry a signed tenant id; see {@see TenantLinkSigner}.
 *
 * The client never sends `X-Tenant-ID` — the tenant travels with the token
 * ability, the session, or the signed link, server-side.
 *
 * Flip-agnostic: the actual `tenancy()->initialize()` is delegated to
 * {@see TenancyResolver::initializeIfProvisioned()}, which is a no-op for the DB
 * switch until a per-tenant schema/database exists (Phase 0b). The resolved
 * tenant id is always recorded on the request for downstream/debug visibility.
 */
class ResolveTenancy
{
    public function __construct(
        private readonly TenancyResolver $tenancyResolver,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolveTenantId($request);

        if ($tenantId !== null) {
            $request->attributes->set('resolved_tenant_id', $tenantId);

            $tenant = Tenant::query()->find($tenantId);
            if ($tenant !== null) {
                // Go-live audit Finding #5: enforce tenant status at REQUEST
                // time, not only at login. A token/session minted while the
                // org was Active must stop working the moment it is suspended
                // or archived (token revocation does not kill cookie sessions).
                if (in_array($tenant->status, [TenantStatus::Suspended, TenantStatus::Archived], true)) {
                    return response()->json([
                        'error' => [
                            'code' => 'ORGANIZATION_UNAVAILABLE',
                            'message' => __('auth.organization_unavailable'),
                        ],
                    ], 403);
                }

                $this->tenancyResolver->initializeIfProvisioned($tenant);
            }
        }

        return $next($request);
    }

    private function resolveTenantId(Request $request): ?string
    {
        // 1. Signed link param (pre-auth verify-email / reset-password).
        $signed = $request->input('tenant');
        if (is_string($signed) && $signed !== '') {
            $fromLink = app(TenantLinkSigner::class)->extract($signed);
            if ($fromLink !== null) {
                return $fromLink;
            }
        }

        // 2. Bearer token tenant ability.
        $bearer = $request->bearerToken();
        if ($bearer !== null) {
            $fromBearer = $this->tenantFromBearer($bearer);
            if ($fromBearer !== null) {
                return $fromBearer;
            }
        }

        // 3. Web SPA session (stamped at login).
        if ($request->hasSession() && $request->session()->has('tenant_id')) {
            $sessionTenant = $request->session()->get('tenant_id');
            if (is_string($sessionTenant) && $sessionTenant !== '') {
                return $sessionTenant;
            }
        }

        return null;
    }

    private function tenantFromBearer(string $bearer): ?string
    {
        // Go-live audit Finding #7: resolve the PAT via the central-pinned
        // model (not the bare Sanctum model) so this security-critical tenant
        // selector always reads the central personal_access_tokens table,
        // regardless of the current default-connection state.
        $token = CentralPersonalAccessToken::findToken($bearer);
        if ($token === null) {
            return null;
        }

        $abilities = is_array($token->abilities) ? $token->abilities : [];
        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'tenant:')) {
                return substr($ability, strlen('tenant:'));
            }
        }

        return null;
    }
}
