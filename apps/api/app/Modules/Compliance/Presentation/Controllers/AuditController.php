<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Compliance\Services\AnomalyDetectionService;
use App\Modules\Compliance\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant isolation (Section 8 / api.compliance round 2):
 * `company_id` is resolved exclusively from `CompanyContext`. The earlier
 * `getCompanyId()` helper read `X-Company-Id` directly from the request
 * header without verifying the user's `UserCompanyMembership` for that
 * company, fell through to a query-string `company_id`, and finally to
 * `$user->companyMemberships()->first()` (any membership). That made
 * cross-tenant audit-event retrieval possible by sending a foreign
 * X-Company-Id. CompanyContextMiddleware (registered in the global `api`
 * group) verifies membership on every request before populating the
 * context, so requireCompanyId() is the safe single source of truth.
 */
class AuditController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly AnomalyDetectionService $anomalyService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List audit events for the current company
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $eventType = $request->query('event_type');
        $aggregateType = $request->query('aggregate_type');
        $aggregateId = $request->query('aggregate_id');
        $from = $request->query('from');
        $to = $request->query('to');

        if ($aggregateType && $aggregateId) {
            $events = $this->auditService->getEventsForAggregate(
                (string) $aggregateType,
                (string) $aggregateId,
                $companyId,
            );
        } elseif ($from && $to) {
            $events = $this->auditService->getEventsInRange(
                $companyId,
                now()->parse((string) $from),
                now()->parse((string) $to),
                $eventType ? (string) $eventType : null
            );
        } elseif ($eventType) {
            $events = $this->auditService->getEventsByType($companyId, (string) $eventType);
        } else {
            $events = $this->auditService->getEventsForCompany($companyId);
        }

        return response()->json([
            'data' => $events->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'aggregate_type' => $event->aggregate_type,
                'aggregate_id' => $event->aggregate_id,
                'payload' => $event->payload,
                'metadata' => $event->metadata,
                'user_id' => $event->user_id,
                'event_hash' => $event->event_hash,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Get detected anomalies
     */
    public function anomalies(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $from = $request->query('from')
            ? now()->parse((string) $request->query('from'))
            : now()->subDay();

        $to = $request->query('to')
            ? now()->parse((string) $request->query('to'))
            : now();

        $anomalies = $this->anomalyService->detectAnomalies($companyId, $from, $to);

        return response()->json([
            'data' => collect($anomalies)->map(fn (array $anomaly) => [
                'type' => $anomaly['type'],
                'severity' => $anomaly['severity'],
                'description' => $anomaly['description'],
                'detected_at' => $anomaly['detected_at'],
                'details' => $anomaly['details'],
            ]),
        ]);
    }
}
