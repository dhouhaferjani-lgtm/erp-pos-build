<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

use App\Models\SuperAdmin;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SupportAccessLogEntryData extends Data
{
    public function __construct(
        public string $id,
        public string $session_id,
        public string $subject_user_id,
        public string $operator_name,
        public SessionEventType $event_type,
        public AuditOutcome $outcome,
        public ?string $action,
        public ?string $http_method,
        public ?string $ticket_ref,
        public string $reason,
        public string $access_level,
        public int $duration_seconds,
        public CarbonInterface $occurred_at,
    ) {}

    public static function fromModel(
        ImpersonationSessionEvent $event,
        ImpersonationSession $session,
        ImpersonationGrant $grant,
        SuperAdmin $operator,
    ): self {
        $endedAt = $session->ended_at;
        if ($endedAt === null) {
            $endedAt = $session->expires_at->isPast() ? $session->expires_at : now();
        }

        return new self(
            id: $event->id,
            session_id: $event->session_id,
            subject_user_id: $event->subject_user_id,
            operator_name: $operator->name,
            event_type: $event->event_type,
            outcome: $event->outcome,
            action: $event->details->route_name,
            http_method: $event->http_method,
            ticket_ref: $event->details->ticket_ref,
            reason: $grant->reason,
            access_level: $session->access_level->value,
            duration_seconds: (int) $session->started_at->diffInSeconds($endedAt),
            occurred_at: $event->occurred_at,
        );
    }
}
