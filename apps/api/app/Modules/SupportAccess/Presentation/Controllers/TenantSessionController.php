<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Controllers;

use App\Modules\Identity\Domain\User;
use App\Modules\SupportAccess\Application\Services\SessionLifecycleService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantSessionController
{
    public function __construct(private readonly SessionLifecycleService $sessions) {}

    public function exit(Request $request, string $session): JsonResponse
    {
        $subject = $request->user();
        if (! $subject instanceof User) {
            throw new AuthenticationException('Tenant user authentication is required.');
        }

        $this->sessions->exit($subject, $session);

        return response()->json(['data' => ['ended' => true]]);
    }
}
