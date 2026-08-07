<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Controllers;

use App\Models\SuperAdmin;
use App\Modules\SupportAccess\Application\Services\ElevationService;
use App\Modules\SupportAccess\Presentation\Requests\DecideElevationRequest;
use App\Modules\SupportAccess\Presentation\Requests\RejectElevationRequest;
use App\Modules\SupportAccess\Presentation\Requests\RequestElevationRequest;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminElevationController
{
    public function __construct(private readonly ElevationService $elevations) {}

    #[CrossTenantRoute(reason: 'The active support operator requests a reason-bound, short-lived write elevation for one central impersonation session.')]
    public function store(RequestElevationRequest $request, string $session): JsonResponse
    {
        return response()->json([
            'data' => $this->elevations->request(
                $this->operator($request),
                $session,
                (string) $request->validated('reason'),
            ),
        ], 201);
    }

    #[CrossTenantRoute(reason: 'A distinct configured business-partner account supplies four-eyes approval for one pending write elevation.')]
    public function approve(DecideElevationRequest $request, string $elevation): JsonResponse
    {
        return response()->json([
            'data' => $this->elevations->approve($this->operator($request), $elevation),
        ]);
    }

    #[CrossTenantRoute(reason: 'A distinct configured business-partner account rejects one pending write elevation with a recorded reason.')]
    public function reject(RejectElevationRequest $request, string $elevation): JsonResponse
    {
        return response()->json([
            'data' => $this->elevations->reject(
                $this->operator($request),
                $elevation,
                (string) $request->validated('reason'),
            ),
        ]);
    }

    private function operator(Request $request): SuperAdmin
    {
        $actor = $request->user();
        if (! $actor instanceof SuperAdmin) {
            throw new AuthenticationException('Support operator authentication is required.');
        }

        return $actor;
    }
}
