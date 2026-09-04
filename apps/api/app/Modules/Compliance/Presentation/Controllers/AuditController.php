<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Compliance\Presentation\Requests\ListAuditEventsRequest;
use App\Modules\Compliance\Services\AnomalyDetectionService;
use App\Modules\Compliance\Services\AuditService;
use Carbon\CarbonImmutable;
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
     * List audit events for the current company.
     *
     * Plan Task 4 (S-3): bounded by ListAuditEventsRequest. `required_with`
     * rejects every half-specified aggregate/date pair before this method
     * runs, so each validated pair is either complete or absent and no
     * half-specified input can fall through to the broader event-type or
     * company branches. Payload/metadata are opt-in via `include=payload`.
     */
    public function index(ListAuditEventsRequest $request): JsonResponse
    {
        $eventTypeInput = $request->validated('event_type');
        $aggregateTypeInput = $request->validated('aggregate_type');
        $aggregateIdInput = $request->validated('aggregate_id');
        $fromInput = $request->validated('from');
        $toInput = $request->validated('to');
        $eventType = is_string($eventTypeInput) ? $eventTypeInput : null;
        $aggregateType = is_string($aggregateTypeInput) ? $aggregateTypeInput : null;
        $aggregateId = is_string($aggregateIdInput) ? $aggregateIdInput : null;
        $from = is_string($fromInput) ? CarbonImmutable::parse($fromInput)->startOfDay() : null;
        $to = is_string($toInput) ? CarbonImmutable::parse($toInput)->endOfDay() : null;
        $hasAggregate = $aggregateType !== null && $aggregateId !== null;
        $hasRange = ! $hasAggregate && $from !== null && $to !== null;
        $oldestFirst = $hasAggregate || $hasRange;

        $events = $this->auditService->paginateEvents(
            companyId: $this->companyContext->requireCompanyId(),
            eventType: $hasAggregate ? null : $eventType,
            aggregateType: $hasAggregate ? $aggregateType : null,
            aggregateId: $hasAggregate ? $aggregateId : null,
            from: $hasRange ? $from : null,
            to: $hasRange ? $to : null,
            perPage: $request->integer('per_page', 50),
            page: $request->integer('page', 1),
            oldestFirst: $oldestFirst,
        );

        $includePayload = $request->validated('include') === 'payload';
        $rows = $events->getCollection()->map(
            /** @return array<string, mixed> */
            static fn (AuditEvent $event): array => array_merge([
                'id' => $event->id,
                'event_type' => $event->event_type,
                'aggregate_type' => $event->aggregate_type,
                'aggregate_id' => $event->aggregate_id,
                'user_id' => $event->user_id,
                'event_hash' => $event->event_hash,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ], $includePayload ? [
                'payload' => $event->payload,
                'metadata' => $event->metadata,
            ] : []),
        )->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'from' => $events->firstItem(),
                'to' => $events->lastItem(),
            ],
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
