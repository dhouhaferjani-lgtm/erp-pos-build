<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireCentralAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $actor = $request->user();
        if (! $actor instanceof SuperAdmin) {
            return $this->deny('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        if (! $actor->is_active) {
            return $this->deny('ACCOUNT_DEACTIVATED', 'This admin account has been deactivated.', 403);
        }

        if (! in_array($actor->role, $roles, true)) {
            return $this->deny('FORBIDDEN', 'This central administrator role cannot perform the action.', 403);
        }

        return $next($request);
    }

    private function deny(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $code,
            'message' => $message,
        ]], $status);
    }
}
