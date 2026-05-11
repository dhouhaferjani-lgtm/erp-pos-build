<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the request as cross-tenant for downstream handlers.
 *
 * Companion to the #[CrossTenantRoute] attribute. Routes wired with the
 * `cross_tenant` middleware alias (registered in bootstrap/app.php) flag
 * their request so tenant-scoping concerns (Eloquent global scopes,
 * presence verifiers, etc.) can read the bool from the request bag and
 * legitimately bypass tenant filters.
 *
 * The middleware itself does NOT authorize cross-tenant access — that is
 * the upstream auth middleware's job (e.g. EnsureSuperAdmin). This is
 * purely a context flag; pairing it with weaker authorization would
 * regress tenant isolation.
 */
final class CrossTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('cross_tenant', true);

        return $next($request);
    }
}
