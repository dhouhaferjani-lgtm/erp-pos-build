<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Modules\SupportAccess\Domain\DTOs\ImpersonationAuditDetailsData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationAuditDelivery;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Modules\SupportAccess\Domain\Services\SessionChainHasher;
use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;
use App\Shared\DTOs\SupportAccess\ImpersonationContextData;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SessionAuditService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly SessionChainHasher $hasher,
        private readonly AuditMirrorDeliveryService $delivery,
    ) {}

    public function recordRequestReceived(
        ImpersonationContextData $context,
        Request $request,
    ): ImpersonationAuditMirrorData {
        return $this->recordRequest(
            context: $context,
            request: $request,
            eventType: SessionEventType::RequestReceived,
            outcome: AuditOutcome::Observed,
        );
    }

    public function recordTerminatingRequest(
        ImpersonationContextData $context,
        Request $request,
        Response $response,
        string $requestId,
    ): ImpersonationAuditMirrorData {
        $status = $response->getStatusCode();
        $failed = $status >= 500;
        $denied = $status >= 400 && ! $failed;
        $errorCode = null;
        if ($denied && $response instanceof JsonResponse) {
            $payload = $response->getData(true);
            $candidate = is_array($payload) ? ($payload['error']['code'] ?? null) : null;
            $errorCode = is_string($candidate) ? $candidate : null;
        }

        return $this->recordRequest(
            context: $context,
            request: $request,
            eventType: $failed
                ? SessionEventType::RequestFailed
                : ($denied ? SessionEventType::RequestDenied : SessionEventType::RequestAuthorized),
            outcome: $failed
                ? AuditOutcome::Failed
                : ($denied ? AuditOutcome::Denied : AuditOutcome::Allowed),
            errorCode: $errorCode,
            responseStatus: $status,
            requestId: $requestId,
            allowEnded: true,
        );
    }

    public function recordFailedRequest(
        ImpersonationContextData $context,
        Request $request,
        string $requestId,
        string $errorCode = 'IMPERSONATION_REQUEST_FAILED',
    ): ImpersonationAuditMirrorData {
        return $this->recordRequest(
            context: $context,
            request: $request,
            eventType: SessionEventType::RequestFailed,
            outcome: AuditOutcome::Failed,
            errorCode: $errorCode,
            responseStatus: 500,
            requestId: $requestId,
            allowEnded: true,
        );
    }

    public function recordExceptionRequest(
        ImpersonationContextData $context,
        Request $request,
        string $requestId,
        Throwable $exception,
    ): ImpersonationAuditMirrorData {
        $status = match (true) {
            $exception instanceof AuthorizationException => 403,
            $exception instanceof ValidationException => 422,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        $failed = $status >= 500;

        return $this->recordRequest(
            context: $context,
            request: $request,
            eventType: $failed ? SessionEventType::RequestFailed : SessionEventType::RequestDenied,
            outcome: $failed ? AuditOutcome::Failed : AuditOutcome::Denied,
            errorCode: $failed ? 'IMPERSONATION_REQUEST_FAILED' : 'IMPERSONATION_REQUEST_DENIED',
            responseStatus: $status,
            requestId: $requestId,
            allowEnded: true,
        );
    }

    public function recordDeniedRequest(
        ImpersonationContextData $context,
        Request $request,
        string $errorCode,
    ): ImpersonationAuditMirrorData {
        return $this->recordRequest(
            context: $context,
            request: $request,
            eventType: SessionEventType::RequestDenied,
            outcome: AuditOutcome::Denied,
            errorCode: $errorCode,
            responseStatus: 403,
        );
    }

    public function recordEndedRequest(
        ImpersonationSession $session,
        string $ticketRef,
        Request $request,
    ): ImpersonationAuditMirrorData {
        return $this->recordRequest(
            context: new ImpersonationContextData(
                operator_id: $session->operator_id,
                session_id: $session->id,
                subject_user_id: $session->subject_user_id,
                subject_name: '',
                tenant_id: $session->tenant_id,
                access_level: $session->access_level,
                reason: '',
                ticket_ref: $ticketRef,
                expires_at: CarbonImmutable::instance($session->expires_at),
            ),
            request: $request,
            eventType: SessionEventType::RequestDenied,
            outcome: AuditOutcome::Denied,
            errorCode: 'IMPERSONATION_ENDED',
            responseStatus: 401,
            allowEnded: true,
        );
    }

    public function recordLifecycleEvent(
        ImpersonationSession $session,
        SessionEventType $eventType,
        ?CarbonImmutable $occurredAt = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $ticketRef = null,
        ?string $grantChainHead = null,
    ): ImpersonationAuditMirrorData {
        return $this->append(
            sessionId: $session->id,
            operatorId: $session->operator_id,
            subjectUserId: $session->subject_user_id,
            tenantId: $session->tenant_id,
            eventType: $eventType,
            outcome: AuditOutcome::Allowed,
            requestId: null,
            httpMethod: null,
            path: null,
            details: new ImpersonationAuditDetailsData(
                ticket_ref: $ticketRef,
                route_name: null,
                response_status: null,
                error_code: null,
                resource_type: $resourceType,
                resource_id: $resourceId,
                grant_chain_head: $grantChainHead,
            ),
            occurredAt: $occurredAt,
        );
    }

    private function recordRequest(
        ImpersonationContextData $context,
        Request $request,
        SessionEventType $eventType,
        AuditOutcome $outcome,
        ?string $errorCode = null,
        ?int $responseStatus = null,
        ?string $requestId = null,
        bool $allowEnded = false,
    ): ImpersonationAuditMirrorData {
        return $this->append(
            sessionId: $context->session_id,
            operatorId: $context->operator_id,
            subjectUserId: $context->subject_user_id,
            tenantId: $context->tenant_id,
            eventType: $eventType,
            outcome: $outcome,
            requestId: $requestId ?? $this->requestId($request),
            httpMethod: strtoupper($request->method()),
            path: '/'.$request->path(),
            details: new ImpersonationAuditDetailsData(
                ticket_ref: $context->ticket_ref,
                route_name: $request->route()?->getName(),
                response_status: $responseStatus,
                error_code: $errorCode,
                resource_type: null,
                resource_id: null,
                request_ip: $request->ip(),
                user_agent: $request->userAgent(),
            ),
            allowEnded: $allowEnded,
        );
    }

    private function requestId(Request $request): string
    {
        $candidate = $request->header('X-Request-ID');

        return is_string($candidate) && Str::isUuid($candidate)
            ? $candidate
            : Str::uuid()->toString();
    }

    private function append(
        string $sessionId,
        string $operatorId,
        string $subjectUserId,
        string $tenantId,
        SessionEventType $eventType,
        AuditOutcome $outcome,
        ?string $requestId,
        ?string $httpMethod,
        ?string $path,
        ImpersonationAuditDetailsData $details,
        ?CarbonImmutable $occurredAt = null,
        bool $allowEnded = false,
    ): ImpersonationAuditMirrorData {
        $mirror = $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        )->transaction(function () use (
            $sessionId,
            $operatorId,
            $subjectUserId,
            $tenantId,
            $eventType,
            $outcome,
            $requestId,
            $httpMethod,
            $path,
            $details,
            $occurredAt,
            $allowEnded,
        ): ImpersonationAuditMirrorData {
            $session = ImpersonationSession::query()->lockForUpdate()->findOrFail($sessionId);
            if ($session->operator_id !== $operatorId
                || $session->subject_user_id !== $subjectUserId
                || $session->tenant_id !== $tenantId
                || (! $allowEnded && $session->ended_at !== null)) {
                throw new RuntimeException('Impersonation session changed before audit append.');
            }

            $sequence = $session->chain_sequence + 1;
            $previousHash = $session->chain_head_hash ?? str_repeat('0', 64);
            $eventOccurredAt = ($occurredAt ?? CarbonImmutable::now('UTC'))->utc()->startOfSecond();
            $mirror = new ImpersonationAuditMirrorData(
                event_id: Str::uuid()->toString(),
                session_id: $session->id,
                sequence: $sequence,
                previous_hash: $previousHash,
                hash: '',
                event_type: $eventType->value,
                outcome: $outcome->value,
                operator_id: $operatorId,
                subject_user_id: $subjectUserId,
                tenant_id: $tenantId,
                request_id: $requestId,
                http_method: $httpMethod,
                path: $path,
                details: $details->toArray(),
                occurred_at: $eventOccurredAt,
            );
            $mirror->hash = $this->hasher->hash($mirror->canonicalPayload());

            ImpersonationSessionEvent::query()->create([
                'id' => $mirror->event_id,
                'session_id' => $mirror->session_id,
                'sequence' => $mirror->sequence,
                'event_type' => $eventType,
                'outcome' => $outcome,
                'operator_id' => $mirror->operator_id,
                'subject_user_id' => $mirror->subject_user_id,
                'tenant_id' => $mirror->tenant_id,
                'request_id' => $mirror->request_id,
                'http_method' => $mirror->http_method,
                'path' => $mirror->path,
                'details' => $details,
                'previous_hash' => $mirror->previous_hash,
                'hash' => $mirror->hash,
                'occurred_at' => $mirror->occurred_at,
            ]);
            ImpersonationAuditDelivery::query()->create([
                'event_id' => $mirror->event_id,
                'aggregate_type' => 'session',
                'tenant_id' => $mirror->tenant_id,
            ]);
            $session->update([
                'chain_sequence' => $sequence,
                'chain_previous_hash' => $previousHash,
                'chain_head_hash' => $mirror->hash,
            ]);

            return $mirror;
        });

        $this->delivery->deliverSession($mirror);

        return $mirror;
    }
}
