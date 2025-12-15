<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure the authenticated user is a SuperAdmin.
 *
 * This middleware verifies that:
 * 1. The user is authenticated
 * 2. The authenticated user is an instance of SuperAdmin (not a tenant User)
 * 3. The SuperAdmin account is active
 *
 * This is critical for security as it prevents tenant users from accessing
 * admin endpoints even if they have a valid Sanctum token.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Authenticatable|null $user */
        $user = $request->user();

        // Check if authenticated
        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                ],
            ], 401);
        }

        // Check if user is a SuperAdmin instance
        if (! $user instanceof SuperAdmin) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Access denied. Super admin privileges required.',
                ],
            ], 403);
        }

        // Check if SuperAdmin is active
        if (! $user->is_active) {
            return response()->json([
                'error' => [
                    'code' => 'ACCOUNT_DEACTIVATED',
                    'message' => 'This admin account has been deactivated.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
