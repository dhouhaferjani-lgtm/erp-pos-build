<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Modules\SupportAccess\Application\DTOs\AuditReconciliationResultData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationAuditDelivery;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrantEvent;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Shared\Contracts\SupportAccess\AdminImpersonationAuditWriter;
use App\Shared\Contracts\SupportAccess\TenantImpersonationAuditWriter;
use App\Shared\DTOs\SupportAccess\GrantAuditMirrorData;
use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

final class AuditMirrorDeliveryService
{
    public function __construct(
        private readonly AdminImpersonationAuditWriter $adminWriter,
        private readonly TenantImpersonationAuditWriter $tenantWriter,
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function deliverSession(ImpersonationAuditMirrorData $event): void
    {
        $this->deliver(
            $event->event_id,
            function () use ($event): void {
                $this->adminWriter->writeImpersonationMirror($event);
            },
            function () use ($event): void {
                $this->tenantWriter->writeImpersonationMirror($event);
            },
        );
    }

    public function deliverGrant(GrantAuditMirrorData $event): void
    {
        $this->deliver(
            $event->event_id,
            function () use ($event): void {
                $this->adminWriter->writeGrantMirror($event);
            },
            function () use ($event): void {
                $this->tenantWriter->writeGrantMirror($event);
            },
        );
    }

    public function reconcilePending(int $limit = 100): AuditReconciliationResultData
    {
        $reconciled = 0;
        $failed = 0;
        $pending = ImpersonationAuditDelivery::query()
            ->where(static fn ($query) => $query
                ->whereNull('admin_delivered_at')
                ->orWhereNull('tenant_delivered_at'))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($pending as $delivery) {
            try {
                if ($delivery->aggregate_type === 'session') {
                    $event = ImpersonationSessionEvent::query()->findOrFail($delivery->event_id);
                    $this->deliverSession(new ImpersonationAuditMirrorData(
                        event_id: $event->id,
                        session_id: $event->session_id,
                        sequence: $event->sequence,
                        previous_hash: $event->previous_hash,
                        hash: $event->hash,
                        event_type: $event->event_type->value,
                        outcome: $event->outcome->value,
                        operator_id: $event->operator_id,
                        subject_user_id: $event->subject_user_id,
                        tenant_id: $event->tenant_id,
                        request_id: $event->request_id,
                        http_method: $event->http_method,
                        path: $event->path,
                        details: $event->details->toArray(),
                        occurred_at: CarbonImmutable::instance($event->occurred_at),
                    ));
                } else {
                    $event = ImpersonationGrantEvent::query()->findOrFail($delivery->event_id);
                    $this->deliverGrant(new GrantAuditMirrorData(
                        event_id: $event->id,
                        grant_id: $event->grant_id,
                        sequence: $event->sequence,
                        previous_hash: $event->previous_hash,
                        hash: $event->hash,
                        event_type: $event->event_type->value,
                        outcome: $event->outcome->value,
                        operator_id: $event->operator_id,
                        subject_user_id: $event->subject_user_id,
                        tenant_id: $event->tenant_id,
                        actor_id: $event->actor_id,
                        actor_type: $event->actor_type,
                        details: $event->details->toArray(),
                        occurred_at: CarbonImmutable::instance($event->occurred_at),
                    ));
                }
                $reconciled++;
            } catch (Throwable $exception) {
                $failed++;
                $this->logger->error('Impersonation audit mirror reconciliation failed.', [
                    'event_id' => $delivery->event_id,
                    'aggregate_type' => $delivery->aggregate_type,
                    'attempt_count' => $delivery->fresh()?->attempt_count,
                    'exception' => $exception,
                ]);
            }
        }

        $remaining = ImpersonationAuditDelivery::query()
            ->where(static fn ($query) => $query
                ->whereNull('admin_delivered_at')
                ->orWhereNull('tenant_delivered_at'))
            ->count();
        if ($remaining > 0) {
            $this->logger->warning('Impersonation audit mirror backlog remains after reconciliation.', [
                'pending' => $remaining,
                'failed' => $failed,
                'limit' => $limit,
            ]);
        }

        return new AuditReconciliationResultData(
            attempted: $pending->count(),
            reconciled: $reconciled,
            failed: $failed,
            pending: $remaining,
        );
    }

    /** @param callable(): mixed $adminDelivery @param callable(): mixed $tenantDelivery */
    private function deliver(string $eventId, callable $adminDelivery, callable $tenantDelivery): void
    {
        $failure = $this->centralConnection()->transaction(function () use (
            $eventId,
            $adminDelivery,
            $tenantDelivery,
        ): ?Throwable {
            $delivery = ImpersonationAuditDelivery::query()->lockForUpdate()->findOrFail($eventId);

            try {
                if ($delivery->admin_delivered_at === null) {
                    $adminDelivery();
                    $delivery->update(['admin_delivered_at' => now(), 'last_error' => null]);
                }
                if ($delivery->tenant_delivered_at === null) {
                    $tenantDelivery();
                    $delivery->update(['tenant_delivered_at' => now(), 'last_error' => null]);
                }
            } catch (Throwable $exception) {
                $delivery->increment('attempt_count');
                $delivery->update(['last_error' => mb_substr($exception->getMessage(), 0, 2000)]);

                return $exception;
            }

            return null;
        });

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    private function centralConnection(): Connection
    {
        return $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        );
    }
}
