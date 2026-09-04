<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\SupportAccess\Infrastructure\Audit\TenantImpersonationAuditStore;
use App\Shared\Contracts\SupportAccess\ImpersonationContextProvider;
use App\Shared\Contracts\SupportAccess\TenantImpersonationAuditWriter;
use App\Shared\DTOs\SupportAccess\GrantAuditMirrorData;
use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

final class AuditService implements TenantImpersonationAuditWriter
{
    public function __construct(
        private readonly ImpersonationContextProvider $impersonationContext,
        private readonly TenantImpersonationAuditStore $impersonationAuditStore,
    ) {}

    public function writeImpersonationMirror(ImpersonationAuditMirrorData $event): void
    {
        $this->impersonationAuditStore->write($event);
    }

    public function writeGrantMirror(GrantAuditMirrorData $event): void
    {
        $this->impersonationAuditStore->writeGrant($event);
    }

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
            attributes: array_filter([
                'tenant_id' => $tenantId,
                'impersonator_id' => $this->impersonationContext->current()?->operator_id,
                'impersonation_session_id' => $this->impersonationContext->current()?->session_id,
                'impersonation_event_id' => $this->impersonationContext->current()?->audit_event_id,
                'impersonation_sequence' => $this->impersonationContext->current()?->audit_sequence,
                'impersonation_previous_hash' => $this->impersonationContext->current()?->audit_previous_hash,
                'impersonation_hash' => $this->impersonationContext->current()?->audit_hash,
            ], static fn (mixed $value): bool => $value !== null)
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
        CarbonInterface $from,
        CarbonInterface $to,
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
        CarbonInterface $from,
        CarbonInterface $to
    ): int {
        return AuditEvent::where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->whereBetween('occurred_at', [$from, $to])
            ->count();
    }

    /**
     * Bounded, ordered page of audit events for the current company.
     *
     * Plan Task 4 (S-3): the controller previously fanned out to four
     * unbounded/limit-100 collection reads and serialized every payload.
     * This single paginator keeps the company predicate on every branch,
     * preserves the ascending `occurred_at` order the aggregate and range
     * branches contract for, keeps descending order elsewhere, and breaks
     * ties on `id` so page traversal is stable.
     *
     * Typed against the concrete \Illuminate\Pagination\LengthAwarePaginator
     * (what Eloquent Builder::paginate() actually returns, Builder.php:1112)
     * rather than the contract, because the contract has no getCollection().
     *
     * @return LengthAwarePaginator<int, AuditEvent>
     */
    public function paginateEvents(
        string $companyId,
        ?string $eventType,
        ?string $aggregateType,
        ?string $aggregateId,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        int $perPage,
        int $page,
        bool $oldestFirst,
    ): LengthAwarePaginator {
        $query = AuditEvent::query()->where('company_id', $companyId);
        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }
        if ($aggregateType !== null && $aggregateId !== null) {
            $query->where('aggregate_type', $aggregateType)->where('aggregate_id', $aggregateId);
        }
        if ($from !== null && $to !== null) {
            $query->whereBetween('occurred_at', [$from, $to]);
        }

        if ($oldestFirst) {
            $query->orderBy('occurred_at');
        } else {
            $query->orderByDesc('occurred_at');
        }

        return $query->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
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
