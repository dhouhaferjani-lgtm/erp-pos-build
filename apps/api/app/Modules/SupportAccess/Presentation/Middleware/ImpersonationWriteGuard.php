<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Middleware;

use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use App\Modules\SupportAccess\Application\Services\SessionAuditService;
use App\Modules\SupportAccess\Domain\Enums\ImpersonationActionDecision;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use App\Modules\SupportAccess\Domain\Services\ImpersonationActionClassifier;
use App\Shared\Contracts\SupportAccess\ImpersonationContextProvider;
use App\Shared\DTOs\SupportAccess\ImpersonationContextData;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ImpersonationWriteGuard
{
    public function __construct(
        private readonly ImpersonationContextProvider $context,
        private readonly ImpersonationActionClassifier $classifier,
        private readonly SessionAuditService $audit,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $decision = $this->classifier->classify($request);
        $context = $this->context->current();

        if ($decision === ImpersonationActionDecision::Safe) {
            return $next($request);
        }

        if ($decision === ImpersonationActionDecision::HardBlocked
            && ($context !== null || $this->hasImpersonationBearer($request))) {
            return $this->deny(
                request: $request,
                context: $context,
                errorCode: 'IMPERSONATION_ACTION_BLOCKED',
                response: $this->blocked(),
            );
        }

        if ($context === null) {
            return $next($request);
        }

        if ($decision === ImpersonationActionDecision::RequiresElevation
            && $context->access_level !== SessionAccessLevel::WriteElevated) {
            return $this->deny($request, $context, 'IMPERSONATION_READ_ONLY', response()->json([
                'error' => [
                    'code' => 'IMPERSONATION_READ_ONLY',
                    'message' => 'This support session is read-only.',
                ],
            ], 403));
        }

        return $next($request);
    }

    private function hasImpersonationBearer(Request $request): bool
    {
        $bearer = $request->bearerToken();
        if ($bearer === null) {
            return false;
        }

        try {
            $currentToken = $request->user()?->currentAccessToken();
            $token = $currentToken instanceof CentralPersonalAccessToken
                ? $currentToken
                : CentralPersonalAccessToken::findToken($bearer);
            $abilities = $token !== null && is_array($token->abilities) ? $token->abilities : [];

            foreach ($abilities as $ability) {
                if (is_string($ability)
                    && (str_starts_with($ability, 'impersonation:')
                        || str_starts_with($ability, 'impersonation-session:'))) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    private function blocked(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'IMPERSONATION_ACTION_BLOCKED',
                'message' => 'This action is never available during support access.',
            ],
        ], 403);
    }

    private function deny(
        Request $request,
        ?ImpersonationContextData $context,
        string $errorCode,
        JsonResponse $response,
    ): JsonResponse {
        if ($context === null) {
            return $response;
        }

        try {
            $this->audit->recordDeniedRequest($context, $request, $errorCode);
        } catch (Throwable) {
            return response()->json([
                'error' => [
                    'code' => 'IMPERSONATION_AUDIT_UNAVAILABLE',
                    'message' => 'Support access is unavailable because its audit trail could not be recorded.',
                ],
            ], 503);
        }

        return $response;
    }
}
