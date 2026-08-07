<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Controllers;

use App\Models\SuperAdmin;
use App\Modules\SupportAccess\Application\Services\SessionLifecycleService;
use App\Modules\SupportAccess\Presentation\Requests\StartSessionRequest;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class AdminSessionController
{
    public function __construct(private readonly SessionLifecycleService $sessions) {}

    #[CrossTenantRoute(reason: 'After valid tenant consent, an authenticated support operator mints a short-lived token on a concrete tenant subject and records the central session.')]
    public function store(StartSessionRequest $request): JsonResponse
    {
        $operator = $request->user();
        if (! $operator instanceof SuperAdmin) {
            throw new AuthenticationException('Support operator authentication is required.');
        }

        return response()->json([
            'data' => $this->sessions->start(
                $operator,
                (string) $request->validated('grant_id'),
                (string) $request->validated('subject_user_id'),
            ),
        ], 201);
    }
}
