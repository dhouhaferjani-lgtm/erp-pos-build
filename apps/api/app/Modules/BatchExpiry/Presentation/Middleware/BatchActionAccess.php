<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Middleware;

use App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class BatchActionAccess
{
    public function __construct(private readonly LotActionPermissionActivation $activation) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->activation->enforced()) {
            return $next($request);
        }
        if (! in_array($permission, ['batches.view', 'batches.traceability', 'batches.delete', 'batches.recall'], true)
            || ! $request->user()?->can($permission)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Permission denied.']], 403);
        }

        return $next($request);
    }
}
