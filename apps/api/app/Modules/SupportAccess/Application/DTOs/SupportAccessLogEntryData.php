<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

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
        public SessionEventType $event_type,
        public AuditOutcome $outcome,
        public ?string $action,
        public ?string $http_method,
        public ?string $ticket_ref,
        public CarbonInterface $occurred_at,
    ) {}

    public static function fromModel(ImpersonationSessionEvent $event): self
    {
        return new self(
            id: $event->id,
            session_id: $event->session_id,
            subject_user_id: $event->subject_user_id,
            event_type: $event->event_type,
            outcome: $event->outcome,
            action: $event->details->route_name,
            http_method: $event->http_method,
            ticket_ref: $event->details->ticket_ref,
            occurred_at: $event->occurred_at,
        );
    }
}
