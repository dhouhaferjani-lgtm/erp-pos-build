<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Modules\SupportAccess\Domain\DTOs\GrantAuditDetailsData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationAuditDelivery;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrantEvent;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Modules\SupportAccess\Domain\Services\SessionChainHasher;
use App\Shared\DTOs\SupportAccess\GrantAuditMirrorData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class GrantAuditService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly SessionChainHasher $hasher,
        private readonly AuditMirrorDeliveryService $delivery,
        private readonly Request $request,
    ) {}

    public function record(
        ImpersonationGrant $grant,
        SessionEventType $eventType,
        AuditOutcome $outcome,
        string $actorId,
        string $actorType,
        ?string $reason = null,
        ?string $approvalPhase = null,
        ?CarbonImmutable $occurredAt = null,
    ): GrantAuditMirrorData {
        $mirror = $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        )->transaction(function () use (
            $grant,
            $eventType,
            $outcome,
            $actorId,
            $actorType,
            $reason,
            $approvalPhase,
            $occurredAt,
        ): GrantAuditMirrorData {
            $locked = ImpersonationGrant::query()->lockForUpdate()->findOrFail($grant->id);
            $sequence = $locked->chain_sequence + 1;
            $previousHash = $locked->chain_head_hash ?? str_repeat('0', 64);
            $details = new GrantAuditDetailsData(
                ticket_ref: $locked->ticket_ref,
                reason: $reason,
                approval_phase: $approvalPhase,
                request_ip: $this->request->ip(),
                user_agent: $this->request->userAgent(),
            );
            $mirror = new GrantAuditMirrorData(
                event_id: Str::uuid()->toString(),
                grant_id: $locked->id,
                sequence: $sequence,
                previous_hash: $previousHash,
                hash: '',
                event_type: $eventType->value,
                outcome: $outcome->value,
                operator_id: $locked->operator_id,
                subject_user_id: $locked->subject_user_id,
                tenant_id: $locked->tenant_id,
                actor_id: $actorId,
                actor_type: $actorType,
                details: $details->toArray(),
                occurred_at: ($occurredAt ?? CarbonImmutable::now('UTC'))->utc()->startOfSecond(),
            );
            $mirror->hash = $this->hasher->hash($mirror->canonicalPayload());

            ImpersonationGrantEvent::query()->create([
                'id' => $mirror->event_id,
                'grant_id' => $mirror->grant_id,
                'sequence' => $mirror->sequence,
                'event_type' => $eventType,
                'outcome' => $outcome,
                'tenant_id' => $mirror->tenant_id,
                'subject_user_id' => $mirror->subject_user_id,
                'operator_id' => $mirror->operator_id,
                'actor_id' => $mirror->actor_id,
                'actor_type' => $mirror->actor_type,
                'details' => $details,
                'previous_hash' => $mirror->previous_hash,
                'hash' => $mirror->hash,
                'occurred_at' => $mirror->occurred_at,
            ]);
            ImpersonationAuditDelivery::query()->create([
                'event_id' => $mirror->event_id,
                'aggregate_type' => 'grant',
                'tenant_id' => $mirror->tenant_id,
            ]);
            $locked->update([
                'chain_sequence' => $sequence,
                'chain_previous_hash' => $previousHash,
                'chain_head_hash' => $mirror->hash,
            ]);

            return $mirror;
        });

        $this->delivery->deliverGrant($mirror);

        return $mirror;
    }
}
