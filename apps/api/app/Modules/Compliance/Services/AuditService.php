<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

final class AuditService
{
    /**
     * Record a new audit event
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $companyId,
        ?string $userId,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload = [],
        array $metadata = []
    ): AuditEvent {
        // Get tenant_id from auth context for performance optimization
        // Avoids Company::find() query in AuditEvent constructor
        $user = Auth::user();
        $tenantId = null;
        if ($user !== null && property_exists($user, 'tenant_id')) {
            /** @var User $user */
            $tenantId = $user->tenant_id;
        } else {
            // Fallback: lookup from company (backward compatibility)
            $company = Company::find($companyId);
            $tenantId = $company?->tenant_id;
        }

        $event = new AuditEvent(
            companyId: $companyId,
            userId: $userId,
            eventType: $eventType,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: $payload,
            metadata: $metadata,
            attributes: [
                'tenant_id' => $tenantId,
            ]
        );

        $event->save();

        return $event;
    }

    /**
     * Get all audit events for a company
     *
     * @return Collection<int, AuditEvent>
     */
    public function getEventsForCompany(string $companyId, int $limit = 100): Collection
    {
        return AuditEvent::where('company_id', $companyId)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit events for a specific aggregate, scoped to a company.
     *
     * Round-2 hardening (Opus api.compliance Finding A): the previous
     * signature took only $aggregateType + $aggregateId and ran an
     * unscoped query. AuditController dispatches into this method with
     * route-supplied aggregate_type / aggregate_id query params; without
     * a company_id predicate, an admin holding compliance.view_reprint_log
     * could submit `?aggregate_type=Document&aggregate_id=<foreign-uuid>`
     * and read tenant-B audit events. The required $companyId param now
     * pins the read to the caller's CompanyContext company. This is the
     * Treasury cluster invariant: BOTH predicates on every read whose
     * anchor came from a route param.
     *
     * @return Collection<int, AuditEvent>
     */
    public function getEventsForAggregate(string $aggregateType, string $aggregateId, string $companyId): Collection
    {
        return AuditEvent::where('company_id', $companyId)
            ->where('aggregate_type', $aggregateType)
            ->where('aggregate_id', $aggregateId)
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * Get audit events within a date range
     *
     * @return Collection<int, AuditEvent>
     */
    public function getEventsInRange(
        string $companyId,
        Carbon $from,
        Carbon $to,
        ?string $eventType = null
    ): Collection {
        $query = AuditEvent::where('company_id', $companyId)
            ->whereBetween('occurred_at', [$from, $to]);

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }

        return $query->orderBy('occurred_at')->get();
    }

    /**
     * Get events by type for a company
     *
     * @return Collection<int, AuditEvent>
     */
    public function getEventsByType(string $companyId, string $eventType, int $limit = 100): Collection
    {
        return AuditEvent::where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Count events of a specific type within a time range
     */
    public function countEventsByType(
        string $companyId,
        string $eventType,
        Carbon $from,
        Carbon $to
    ): int {
        return AuditEvent::where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->whereBetween('occurred_at', [$from, $to])
            ->count();
    }

    /**
     * Get events by user
     *
     * @return Collection<int, AuditEvent>
     */
    public function getEventsByUser(string $companyId, string $userId, int $limit = 100): Collection
    {
        return AuditEvent::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }
}
