<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Console;

use App\Models\AdminAuditLog;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrantEvent;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Services\GrantChainVerifier;
use App\Modules\SupportAccess\Domain\Services\SessionChainVerifier;
use App\Modules\SupportAccess\Infrastructure\Audit\TenantImpersonationAuditStore;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class VerifyImpersonationAuditCommand extends Command
{
    protected $signature = 'support-access:audit-verify
        {--session= : Impersonation session UUID}
        {--grant= : Impersonation grant UUID}';

    protected $description = 'Verify an impersonation session chain and both audit mirrors';

    public function __construct(
        private readonly SessionChainVerifier $verifier,
        private readonly GrantChainVerifier $grantVerifier,
        private readonly TenantImpersonationAuditStore $tenantAuditStore,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sessionId = $this->option('session');
        $grantId = $this->option('grant');
        if (is_string($grantId) && $grantId !== '') {
            return $this->verifyGrant($grantId);
        }
        if (! is_string($sessionId) || $sessionId === '') {
            $this->error('Exactly one of --session or --grant is required.');

            return self::FAILURE;
        }

        $session = ImpersonationSession::query()->find($sessionId);
        if ($session === null) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        $events = ImpersonationSessionEvent::query()
            ->where('session_id', $sessionId)
            ->orderBy('sequence')
            ->get();
        $rows = [];
        $eventIds = [];
        foreach ($events as $event) {
            $eventIds[] = $event->id;
            $rows[] = [
                'version' => 1,
                'event_id' => $event->id,
                'session_id' => $event->session_id,
                'sequence' => $event->sequence,
                'previous_hash' => $event->previous_hash,
                'event_type' => $event->event_type->value,
                'outcome' => $event->outcome->value,
                'operator_id' => $event->operator_id,
                'subject_user_id' => $event->subject_user_id,
                'tenant_id' => $event->tenant_id,
                'request_id' => $event->request_id,
                'http_method' => $event->http_method,
                'path' => $event->path,
                'details' => $event->details->toArray(),
                'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'hash' => $event->hash,
            ];
        }
        $result = $this->verifier->verify($rows, $session->chain_head_hash);
        if (! $result->valid) {
            $this->error("Chain invalid at sequence {$result->failed_sequence}: {$result->error}");

            return self::FAILURE;
        }

        $adminMirrors = AdminAuditLog::query()
            ->where('impersonation_session_id', $sessionId)
            ->whereIn('impersonation_event_id', $eventIds)
            ->get()
            ->keyBy('impersonation_event_id');
        $tenantMirrors = $this->tenantMirrors($session->tenant_id, $eventIds)
            ->keyBy('impersonation_event_id');
        if ($adminMirrors->count() !== $events->count() || $tenantMirrors->count() !== $events->count()) {
            $this->error('Mirror count mismatch.');

            return self::FAILURE;
        }

        foreach ($events as $event) {
            $admin = $adminMirrors->get($event->id);
            $tenant = $tenantMirrors->get($event->id);
            if (! $admin instanceof AdminAuditLog || ! $this->mirrorMatches($admin, $event)) {
                $this->error("Admin mirror content mismatch for event {$event->id}.");

                return self::FAILURE;
            }
            if (! $tenant instanceof AuditEvent || ! $this->mirrorMatches($tenant, $event)) {
                $this->error("Tenant mirror content mismatch for event {$event->id}.");

                return self::FAILURE;
            }
        }

        $this->info("Verified {$events->count()} event(s) and both mirrors.");

        return self::SUCCESS;
    }

    private function verifyGrant(string $grantId): int
    {
        $grant = ImpersonationGrant::query()->find($grantId);
        if ($grant === null) {
            $this->error('Grant not found.');

            return self::FAILURE;
        }

        $events = ImpersonationGrantEvent::query()
            ->where('grant_id', $grantId)
            ->orderBy('sequence')
            ->get();
        $result = $this->grantVerifier->verify($events, $grant->chain_head_hash);
        if (! $result->valid) {
            $this->error("Grant chain invalid at sequence {$result->failed_sequence}: {$result->error}");

            return self::FAILURE;
        }

        $eventIds = $events->pluck('id')->all();
        $adminMirrors = AdminAuditLog::query()->whereIn('impersonation_event_id', $eventIds)
            ->get()->keyBy('impersonation_event_id');
        $tenantMirrors = $this->tenantMirrors($grant->tenant_id, $eventIds)
            ->keyBy('impersonation_event_id');
        if ($adminMirrors->count() !== $events->count() || $tenantMirrors->count() !== $events->count()) {
            $this->error('Grant mirror count mismatch.');

            return self::FAILURE;
        }

        foreach ($events as $event) {
            $admin = $adminMirrors->get($event->id);
            $tenant = $tenantMirrors->get($event->id);
            if (! $admin instanceof AdminAuditLog || ! $this->grantMirrorMatches($admin, $event)) {
                $this->error("Admin grant mirror content mismatch for event {$event->id}.");

                return self::FAILURE;
            }
            if (! $tenant instanceof AuditEvent || ! $this->grantMirrorMatches($tenant, $event)) {
                $this->error("Tenant grant mirror content mismatch for event {$event->id}.");

                return self::FAILURE;
            }
        }

        $this->info("Verified {$events->count()} grant event(s) and both mirrors.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $eventIds
     * @return Collection<int, AuditEvent>
     */
    private function tenantMirrors(string $tenantId, array $eventIds): Collection
    {
        return $this->tenantAuditStore->forEvents($tenantId, $eventIds);
    }

    private function mirrorMatches(Model $mirror, ImpersonationSessionEvent $event): bool
    {
        $linkageMatches = $mirror->getAttribute('impersonator_id') === $event->operator_id
            && $mirror->getAttribute('impersonation_session_id') === $event->session_id
            && $mirror->getAttribute('impersonation_event_id') === $event->id
            && (int) $mirror->getAttribute('impersonation_sequence') === $event->sequence
            && $mirror->getAttribute('impersonation_previous_hash') === $event->previous_hash
            && $mirror->getAttribute('impersonation_hash') === $event->hash;
        if (! $linkageMatches) {
            return false;
        }

        if ($mirror instanceof AdminAuditLog) {
            return $mirror->action === 'impersonation_'.$event->event_type->value
                && $mirror->entity_type === 'impersonation_session'
                && $mirror->entity_id === $event->session_id
                && $this->arraysMatch($mirror->new_values ?? [], [
                    'outcome' => $event->outcome->value,
                    'method' => $event->http_method,
                    'path' => $event->path,
                    'details' => $event->details->toArray(),
                ])
                && $mirror->ip_address === $event->details->request_ip
                && $mirror->user_agent === $event->details->user_agent;
        }

        if (! $mirror instanceof AuditEvent) {
            return false;
        }

        return $mirror->event_type === 'support_access.'.$event->event_type->value
            && $mirror->aggregate_type === 'ImpersonationSession'
            && $mirror->aggregate_id === $event->session_id
            && $this->arraysMatch($mirror->payload, $event->details->toArray())
            && $this->arraysMatch($mirror->metadata, [
                'outcome' => $event->outcome->value,
                'method' => $event->http_method,
                'path' => $event->path,
            ])
            && hash_equals($mirror->event_hash, $this->expectedTenantHash(
                userId: $event->subject_user_id,
                eventType: 'support_access.'.$event->event_type->value,
                aggregateType: 'ImpersonationSession',
                aggregateId: $event->session_id,
                payload: $event->details->toArray(),
                occurredAt: $event->occurred_at,
            ));
    }

    private function grantMirrorMatches(Model $mirror, ImpersonationGrantEvent $event): bool
    {
        $linkageMatches = $mirror->getAttribute('impersonator_id') === $event->operator_id
            && $mirror->getAttribute('impersonation_session_id') === null
            && $mirror->getAttribute('impersonation_event_id') === $event->id
            && (int) $mirror->getAttribute('impersonation_sequence') === $event->sequence
            && $mirror->getAttribute('impersonation_previous_hash') === $event->previous_hash
            && $mirror->getAttribute('impersonation_hash') === $event->hash;
        if (! $linkageMatches) {
            return false;
        }

        if ($mirror instanceof AdminAuditLog) {
            return $mirror->action === 'impersonation_'.$event->event_type->value
                && $mirror->entity_type === 'impersonation_grant'
                && $mirror->entity_id === $event->grant_id
                && $this->arraysMatch($mirror->new_values ?? [], [
                    'outcome' => $event->outcome->value,
                    'actor_id' => $event->actor_id,
                    'actor_type' => $event->actor_type,
                    'details' => $event->details->toArray(),
                ])
                && $mirror->ip_address === $event->details->request_ip
                && $mirror->user_agent === $event->details->user_agent;
        }

        if (! $mirror instanceof AuditEvent) {
            return false;
        }

        return $mirror->event_type === 'support_access.'.$event->event_type->value
            && $mirror->aggregate_type === 'ImpersonationGrant'
            && $mirror->aggregate_id === $event->grant_id
            && $this->arraysMatch($mirror->payload, $event->details->toArray())
            && $this->arraysMatch($mirror->metadata, [
                'outcome' => $event->outcome->value,
                'actor_id' => $event->actor_id,
                'actor_type' => $event->actor_type,
            ])
            && hash_equals($mirror->event_hash, $this->expectedTenantHash(
                userId: $event->actor_type === 'tenant_user' ? $event->actor_id : $event->subject_user_id,
                eventType: 'support_access.'.$event->event_type->value,
                aggregateType: 'ImpersonationGrant',
                aggregateId: $event->grant_id,
                payload: $event->details->toArray(),
                occurredAt: $event->occurred_at,
            ));
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function arraysMatch(array $left, array $right): bool
    {
        return json_encode($this->canonicalize($left), JSON_THROW_ON_ERROR)
            === json_encode($this->canonicalize($right), JSON_THROW_ON_ERROR);
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function expectedTenantHash(
        ?string $userId,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        CarbonInterface $occurredAt,
    ): string {
        $expected = new AuditEvent;
        $expected->companyId = '';
        $expected->userId = $userId;
        $expected->eventType = $eventType;
        $expected->aggregateType = $aggregateType;
        $expected->aggregateId = $aggregateId;
        $expected->occurredAt = $occurredAt;
        $expected->forceFill(['payload' => $payload]);
        $expected->recomputeHash();

        return $expected->eventHash;
    }
}
