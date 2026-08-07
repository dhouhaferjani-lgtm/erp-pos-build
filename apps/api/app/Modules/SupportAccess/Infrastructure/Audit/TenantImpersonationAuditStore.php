<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Infrastructure\Audit;

use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\DTOs\SupportAccess\GrantAuditMirrorData;
use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class TenantImpersonationAuditStore
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
    ) {}

    public function write(ImpersonationAuditMirrorData $event): void
    {
        $this->run($event->tenant_id, function () use ($event): void {
            $payload = $this->normalizeJsonPayload($event->details);
            $audit = new AuditEvent;
            $audit->companyId = '';
            $audit->userId = $event->subject_user_id;
            $audit->eventType = 'support_access.'.$event->event_type;
            $audit->aggregateType = 'ImpersonationSession';
            $audit->aggregateId = $event->session_id;
            $audit->occurredAt = Carbon::instance($event->occurred_at);
            $audit->forceFill([
                'tenant_id' => $event->tenant_id,
                // company_id=null deliberately excludes support-access mirrors from
                // NF525 company chains; impersonation_hash is the independent chain.
                'company_id' => null,
                'user_id' => $event->subject_user_id,
                'event_type' => $audit->eventType,
                'aggregate_type' => $audit->aggregateType,
                'aggregate_id' => $audit->aggregateId,
                'payload' => $payload,
                'metadata' => [
                    'outcome' => $event->outcome,
                    'method' => $event->http_method,
                    'path' => $event->path,
                ],
                'occurred_at' => $event->occurred_at,
                'impersonator_id' => $event->operator_id,
                'impersonation_session_id' => $event->session_id,
                'impersonation_event_id' => $event->event_id,
                'impersonation_sequence' => $event->sequence,
                'impersonation_previous_hash' => $event->previous_hash,
                'impersonation_hash' => $event->hash,
            ]);
            $audit->recomputeHash();
            $this->saveIdempotently($audit);
        });
    }

    public function writeGrant(GrantAuditMirrorData $event): void
    {
        $this->run($event->tenant_id, function () use ($event): void {
            $payload = $this->normalizeJsonPayload($event->details);
            $audit = new AuditEvent;
            $audit->companyId = '';
            $audit->userId = $event->actor_type === 'tenant_user' ? $event->actor_id : ($event->subject_user_id ?? '');
            $audit->eventType = 'support_access.'.$event->event_type;
            $audit->aggregateType = 'ImpersonationGrant';
            $audit->aggregateId = $event->grant_id;
            $audit->occurredAt = Carbon::instance($event->occurred_at);
            $audit->forceFill([
                'tenant_id' => $event->tenant_id,
                'company_id' => null,
                'user_id' => $audit->userId === '' ? null : $audit->userId,
                'event_type' => $audit->eventType,
                'aggregate_type' => $audit->aggregateType,
                'aggregate_id' => $audit->aggregateId,
                'payload' => $payload,
                'metadata' => [
                    'outcome' => $event->outcome,
                    'actor_id' => $event->actor_id,
                    'actor_type' => $event->actor_type,
                ],
                'occurred_at' => $event->occurred_at,
                'impersonator_id' => $event->operator_id,
                'impersonation_session_id' => null,
                'impersonation_event_id' => $event->event_id,
                'impersonation_sequence' => $event->sequence,
                'impersonation_previous_hash' => $event->previous_hash,
                'impersonation_hash' => $event->hash,
            ]);
            $audit->recomputeHash();
            $this->saveIdempotently($audit);
        });
    }

    /**
     * @param  list<string>  $eventIds
     * @return Collection<int, AuditEvent>
     */
    public function forEvents(string $tenantId, array $eventIds): Collection
    {
        return $this->run($tenantId, static fn (): Collection => AuditEvent::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('impersonation_event_id', $eventIds)
            ->where('event_type', 'like', 'support_access.%')
            ->get());
    }

    /** @template TResult
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function run(string $tenantId, Closure $callback): mixed
    {
        $central = $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        );
        if (! (bool) $this->config->get('tenancy_resolver.db_per_tenant', false)
            && $central->getSchemaBuilder()->hasTable('audit_events')) {
            return $callback();
        }

        $tenant = Tenant::query()->findOrFail($tenantId);

        return $tenant->run($callback);
    }

    private function saveIdempotently(AuditEvent $audit): void
    {
        if ($this->mirrorExists($audit)) {
            return;
        }

        try {
            $audit->saveOrFail();
        } catch (QueryException $exception) {
            if ($this->mirrorExists($audit)) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * PostgreSQL jsonb normalizes object key order. Hash the same decoded
     * representation that will be returned after persistence so the legacy
     * AuditEvent hash remains reproducible without changing calculateHash().
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeJsonPayload(array $payload): array
    {
        $connection = $this->database->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            return $payload;
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $normalized = $connection->scalar('SELECT CAST(? AS jsonb)::text', [$encoded]);
        if (! is_string($normalized)) {
            throw new \RuntimeException('PostgreSQL did not return a normalized audit payload.');
        }

        $decoded = json_decode($normalized, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Normalized audit payload is not an object.');
        }

        return $decoded;
    }

    /** @phpstan-impure */
    private function mirrorExists(AuditEvent $audit): bool
    {
        return AuditEvent::query()
            ->where('impersonation_event_id', $audit->impersonation_event_id)
            ->where('event_type', $audit->event_type)
            ->where('aggregate_type', $audit->aggregate_type)
            ->exists();
    }
}
