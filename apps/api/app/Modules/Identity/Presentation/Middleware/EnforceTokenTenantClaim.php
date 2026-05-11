<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Middleware;

use App\Modules\Identity\Domain\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth: rejects requests whose Sanctum personal-access token
 * encodes a `tenant:<uuid>` ability that does not match the live
 * `User::tenant_id`.
 *
 * Sits AFTER `auth:sanctum` (so `currentAccessToken()` is resolved) and
 * AFTER {@see SetPermissionsTeam} (so the permissions team-id is bound
 * before any downstream check) per the master-plan §15 ordering.
 *
 * GRANDFATHERING (option (b) per triage Section C.5)
 *
 * Tokens issued before this PR lack the `tenant:` ability. Rejecting
 * them at deploy would force every active session to re-login. The
 * middleware therefore allows tokens with NO `tenant:` ability through
 * (grandfathering); only tokens carrying a MISMATCHING claim are rejected.
 *
 * EXEMPTIONS
 *
 *   1. Unauthenticated request → pass (upstream `auth:sanctum` will reject).
 *   2. Non-`User` authenticatable (e.g., SuperAdmin) → pass.
 *   3. Non-PAT auth (session cookie / TransientToken) → pass.
 *   4. Token carries `super-admin` ability → pass.
 *   5. Token carries no `tenant:` ability → pass (grandfathering).
 *
 * Reject path produces 401 with code `TOKEN_TENANT_MISMATCH`.
 *
 * Race window with Invariant A (lifecycle hooks): A token can be issued
 * with the correct claim, the user's tenant_id can change, and the
 * lifecycle hook revokes the token — but a request mid-flight may carry
 * the now-stale claim. This middleware short-circuits the stale request
 * to 401 even if the lifecycle hook has not yet committed. That is the
 * defense-in-depth value of layering D atop A.
 */
class EnforceTokenTenantClaim
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user instanceof User) {
            return $next($request);
        }

        // Sanctum's `HasApiTokens` template defaults the return type to
        // `PersonalAccessToken`, but at runtime `currentAccessToken()` can
        // also return null (session-cookie auth without a stored token) or
        // a `TransientToken` (session-cookie auth with stateful pipeline).
        // Re-widen the value to `?HasAbilities` via the helper so that the
        // narrowing checks below are not flagged as always-true / always-false.
        $pat = $this->resolvePersonalAccessToken($user->currentAccessToken());

        if ($pat === null) {
            return $next($request);
        }

        $abilities = $this->extractAbilities($pat);

        if (in_array('super-admin', $abilities, true)) {
            return $next($request);
        }

        $tenantAbility = null;
        foreach ($abilities as $ability) {
            if (str_starts_with($ability, 'tenant:')) {
                $tenantAbility = $ability;
                break;
            }
        }

        if ($tenantAbility === null) {
            return $next($request);
        }

        $expectedTenantId = substr($tenantAbility, strlen('tenant:'));

        if ($expectedTenantId !== $user->tenant_id) {
            return response()->json([
                'error' => [
                    'code' => 'TOKEN_TENANT_MISMATCH',
                    'message' => 'Token tenant claim does not match user tenant.',
                ],
            ], 401);
        }

        return $next($request);
    }

    private function resolvePersonalAccessToken(?HasAbilities $token): ?PersonalAccessToken
    {
        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /**
     * @return list<string>
     */
    private function extractAbilities(PersonalAccessToken $token): array
    {
        $raw = $token->abilities;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $ability) {
            if (is_string($ability)) {
                $out[] = $ability;
            }
        }

        return $out;
    }
}
