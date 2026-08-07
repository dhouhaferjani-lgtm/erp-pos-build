<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Models\SuperAdmin;
use App\Modules\SupportAccess\Application\DTOs\ElevationData;
use App\Modules\SupportAccess\Application\DTOs\GrantData;
use App\Modules\SupportAccess\Application\DTOs\OffsetPaginationMetaData;
use App\Modules\SupportAccess\Application\DTOs\SessionData;
use App\Modules\SupportAccess\Application\DTOs\SupportAccessLogEntryData;
use App\Modules\SupportAccess\Application\DTOs\SupportAccessOverviewData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationElevation;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Enums\ElevationStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use Illuminate\Contracts\Config\Repository;

final class SupportAccessQueryService
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /** @return array{overview: SupportAccessOverviewData, current_page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null} */
    public function adminOverview(string $operatorId, bool $isApprover, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $query = ImpersonationGrant::query()->orderByDesc('requested_at')->orderByDesc('id');
        if ($isApprover) {
            $query->where('status', GrantStatus::PendingInternalApproval);
        } else {
            $query->where('operator_id', $operatorId);
        }
        $total = $query->count();
        $grants = [];
        foreach ($query->forPage($page, $perPage)->get() as $grant) {
            $grants[] = GrantData::fromModel($grant);
        }
        $from = $grants === [] ? null : (($page - 1) * $perPage) + 1;
        $to = $from === null ? null : $from + count($grants) - 1;
        $pendingElevations = [];
        $elevations = ImpersonationElevation::query()
            ->where('status', ElevationStatus::Pending)
            ->when(! $isApprover, static fn ($query) => $query->whereIn(
                'session_id',
                ImpersonationSession::query()->select('id')->where('operator_id', $operatorId),
            ))
            ->orderBy('requested_at')
            ->limit(100)
            ->get();
        foreach ($elevations as $elevation) {
            $pendingElevations[] = ElevationData::fromModel($elevation);
        }

        return [
            'overview' => new SupportAccessOverviewData(
                max_grant_window_hours: $this->maxGrantWindowHours(),
                grants: $grants,
                active_sessions: $this->activeSessions(operatorId: $operatorId),
                log: [],
                pending_elevations: $pendingElevations,
            ),
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'from' => $from,
            'to' => $to,
        ];
    }

    public function tenantOverview(string $tenantId, int $page, int $perPage): SupportAccessOverviewData
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $grants = ImpersonationGrant::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('requested_at')
            ->limit(100)
            ->get();

        $grantData = [];
        foreach ($grants as $grant) {
            $grantData[] = GrantData::fromModel($grant);
        }
        $log = [];
        $eventQuery = ImpersonationSessionEvent::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('sequence');
        $total = $eventQuery->count();
        $events = $eventQuery->forPage($page, $perPage)->get();
        foreach ($events as $event) {
            $session = ImpersonationSession::query()->findOrFail($event->session_id);
            $grant = ImpersonationGrant::query()->findOrFail($session->grant_id);
            $operator = SuperAdmin::query()->findOrFail($event->operator_id);
            $log[] = SupportAccessLogEntryData::fromModel($event, $session, $grant, $operator);
        }
        $from = $log === [] ? null : (($page - 1) * $perPage) + 1;
        $to = $from === null ? null : $from + count($log) - 1;

        return new SupportAccessOverviewData(
            max_grant_window_hours: $this->maxGrantWindowHours(),
            grants: $grantData,
            active_sessions: $this->activeSessions($tenantId),
            log: $log,
            pending_elevations: [],
            log_meta: new OffsetPaginationMetaData(
                current_page: $page,
                per_page: $perPage,
                total: $total,
                last_page: max(1, (int) ceil($total / $perPage)),
                from: $from,
                to: $to,
            ),
        );
    }

    private function maxGrantWindowHours(): int
    {
        $hours = $this->config->get('support_access.max_grant_window_hours', 168);

        return is_int($hours) && $hours > 0 ? $hours : 168;
    }

    /** @return list<SessionData> */
    private function activeSessions(?string $tenantId = null, ?string $operatorId = null): array
    {
        $query = ImpersonationSession::query()
            ->whereNull('ended_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('started_at')
            ->limit(100);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        if ($operatorId !== null) {
            $query->where('operator_id', $operatorId);
        }

        $sessions = [];
        foreach ($query->get() as $session) {
            $grant = ImpersonationGrant::query()->findOrFail($session->grant_id);
            $sessions[] = SessionData::fromModel($session, $grant);
        }

        return $sessions;
    }
}
