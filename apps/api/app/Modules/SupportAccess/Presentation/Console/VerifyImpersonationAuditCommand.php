<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Console;

use App\Models\AdminAuditLog;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Services\SessionChainVerifier;
use App\Modules\SupportAccess\Infrastructure\Audit\TenantImpersonationAuditStore;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class VerifyImpersonationAuditCommand extends Command
{
    protected $signature = 'support-access:audit-verify {--session= : Impersonation session UUID}';

    protected $description = 'Verify an impersonation session chain and both audit mirrors';

    public function __construct(
        private readonly SessionChainVerifier $verifier,
        private readonly TenantImpersonationAuditStore $tenantAuditStore,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sessionId = $this->option('session');
        if (! is_string($sessionId) || $sessionId === '') {
            $this->error('--session is required.');

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
            if (! $admin instanceof AdminAuditLog || ! $tenant instanceof AuditEvent
                || ! $this->mirrorMatches($admin, $event)
                || ! $this->mirrorMatches($tenant, $event)) {
                $this->error("Mirror content mismatch for event {$event->id}.");

                return self::FAILURE;
            }
        }

        $this->info("Verified {$events->count()} event(s) and both mirrors.");

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
        return $mirror->getAttribute('impersonator_id') === $event->operator_id
            && $mirror->getAttribute('impersonation_session_id') === $event->session_id
            && $mirror->getAttribute('impersonation_event_id') === $event->id
            && (int) $mirror->getAttribute('impersonation_sequence') === $event->sequence
            && $mirror->getAttribute('impersonation_previous_hash') === $event->previous_hash
            && $mirror->getAttribute('impersonation_hash') === $event->hash;
    }
}
