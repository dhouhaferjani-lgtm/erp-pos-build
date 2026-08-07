<?php

declare(strict_types=1);

namespace App\Shared\DTOs\SupportAccess;

use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class ImpersonationContextData extends Data
{
    public function __construct(
        public string $operator_id,
        public string $session_id,
        public string $subject_user_id,
        public string $subject_name,
        public string $tenant_id,
        public SessionAccessLevel $access_level,
        public string $reason,
        public string $ticket_ref,
        public CarbonImmutable $expires_at,
        public ?string $audit_event_id = null,
        public ?int $audit_sequence = null,
        public ?string $audit_previous_hash = null,
        public ?string $audit_hash = null,
    ) {}
}
