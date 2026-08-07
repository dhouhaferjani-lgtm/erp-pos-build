<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SessionData extends Data
{
    public function __construct(
        public string $id,
        public string $grant_id,
        public string $subject_user_id,
        public SessionAccessLevel $access_level,
        public CarbonInterface $started_at,
        public CarbonInterface $expires_at,
        public ?CarbonInterface $ended_at,
        public string $reason,
        public string $ticket_ref,
    ) {}

    public static function fromModel(ImpersonationSession $session, ImpersonationGrant $grant): self
    {
        return new self(
            id: $session->id,
            grant_id: $session->grant_id,
            subject_user_id: $session->subject_user_id,
            access_level: $session->access_level,
            started_at: $session->started_at,
            expires_at: $session->expires_at,
            ended_at: $session->ended_at,
            reason: $grant->reason,
            ticket_ref: $grant->ticket_ref,
        );
    }
}
