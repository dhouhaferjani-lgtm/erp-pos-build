<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Modules\SupportAccess\Domain\DTOs\ImpersonationAuditDetailsData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Modules\SupportAccess\Domain\Services\SessionChainHasher;
use App\Shared\Contracts\SupportAccess\AdminImpersonationAuditWriter;
use App\Shared\Contracts\SupportAccess\TenantImpersonationAuditWriter;
use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;
use App\Shared\DTOs\SupportAccess\ImpersonationContextData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class SessionAuditService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly SessionChainHasher $hasher,
        private readonly AdminImpersonationAuditWriter $adminWriter,
        private readonly TenantImpersonationAuditWriter $tenantWriter,
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
        $denied = $status >= 400;
        $errorCode = null;
        if ($denied && $response instanceof JsonResponse) {
            $payload = $response->getData(true);
            $candidate = is_array($payload) ? ($payload['error']['code'] ?? null) : null;
            $errorCode = is_string($candidate) ? $candidate : null;
        }

        return $this->recordRequest(
            context: $context,
            request: $request,
            eventType: $denied ? SessionEventType::RequestDenied : SessionEventType::RequestAuthorized,
            outcome: $denied ? AuditOutcome::Denied : AuditOutcome::Allowed,
            errorCode: $errorCode,
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

    public function recordLifecycleEvent(
        ImpersonationSession $session,
        SessionEventType $eventType,
        ?CarbonImmutable $occurredAt = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $ticketRef = null,
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
            $session->update([
                'chain_sequence' => $sequence,
                'chain_previous_hash' => $previousHash,
                'chain_head_hash' => $mirror->hash,
            ]);

            return $mirror;
        });

        $this->adminWriter->writeImpersonationMirror($mirror);
        $this->tenantWriter->writeImpersonationMirror($mirror);

        return $mirror;
    }
}
