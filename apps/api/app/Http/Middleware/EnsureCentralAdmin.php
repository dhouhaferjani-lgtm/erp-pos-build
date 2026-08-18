<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Enums\SuperAdminRole;
use App\Models\SuperAdmin;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCentralAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        if (! $actor instanceof SuperAdmin) {
            return $this->deny('UNAUTHENTICATED', trans('country_defaults.auth.unauthenticated'), 401);
        }
        if (! $actor->is_active) {
            return $this->deny('ACCOUNT_DEACTIVATED', trans('country_defaults.auth.deactivated'), 403);
        }
        if ($actor->role === SuperAdminRole::DefaultsEditor->value
            && ! config('country_defaults.external_editors_enabled', false)) {
            return $this->deny(
                'EXTERNAL_EDITORS_DISABLED',
                trans('country_defaults.auth.external_editors_disabled'),
                403,
            );
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
