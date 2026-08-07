<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

use App\Shared\DTOs\SupportAccess\ImpersonationContextData;
use Carbon\CarbonImmutable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class ImpersonationContextViewData
{
    public function __construct(
        public string $session_id,
        public string $subject_user_id,
        public string $subject_name,
        public string $reason,
        public string $ticket_ref,
        public string $access_level,
        public string $expires_at,
        public int $remaining_seconds,
    ) {}

    public static function fromContext(ImpersonationContextData $context): self
    {
        return new self(
            session_id: $context->session_id,
            subject_user_id: $context->subject_user_id,
            subject_name: $context->subject_name,
            reason: $context->reason,
            ticket_ref: $context->ticket_ref,
            access_level: $context->access_level->value,
            expires_at: $context->expires_at->toIso8601String(),
            remaining_seconds: max(0, $context->expires_at->getTimestamp() - CarbonImmutable::now()->getTimestamp()),
        );
    }
}
