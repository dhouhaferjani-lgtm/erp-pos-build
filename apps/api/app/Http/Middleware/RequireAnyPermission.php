<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Require at least one of the route's comma-separated permissions. */
final class RequireAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $allowed = $user !== null && collect($permissions)->contains(
            static fn (string $permission): bool => $user->can($permission),
        );

        abort_unless($allowed, 403);

        return $next($request);
    }
}
