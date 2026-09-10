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

        if (! $allowed) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to perform this action.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 403);
        }

        return $next($request);
    }
}
