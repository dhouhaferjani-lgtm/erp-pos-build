<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Middleware;

use App\Modules\SupportAccess\Application\Services\RequestImpersonationContext;
use App\Modules\SupportAccess\Application\Services\SessionAuditService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ImpersonationAudit
{
    public function __construct(
        private readonly RequestImpersonationContext $context,
        private readonly SessionAuditService $audit,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->context->current();
        if ($context === null) {
            return $next($request);
        }

        try {
            $mirror = $this->audit->recordRequestReceived($context, $request);
            $context->audit_event_id = $mirror->event_id;
            $context->audit_sequence = $mirror->sequence;
            $context->audit_previous_hash = $mirror->previous_hash;
            $context->audit_hash = $mirror->hash;
            if ($mirror->request_id === null) {
                throw new RuntimeException('Request-received audit event must carry a request ID.');
            }
        } catch (Throwable) {
            return $this->unavailable();
        }

        $response = $next($request);

        try {
            $this->audit->recordTerminatingRequest($context, $request, $response, $mirror->request_id);
        } catch (Throwable) {
            return $this->unavailable();
        }

        return $response;
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'IMPERSONATION_AUDIT_UNAVAILABLE',
                'message' => 'Support access is unavailable because its audit trail could not be recorded.',
            ],
        ], 503);
    }
}
