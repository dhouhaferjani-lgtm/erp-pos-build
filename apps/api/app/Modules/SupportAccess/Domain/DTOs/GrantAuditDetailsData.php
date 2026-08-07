<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\DTOs;

use Spatie\LaravelData\Data;

final class GrantAuditDetailsData extends Data
{
    public function __construct(
        public string $ticket_ref,
        public ?string $reason,
        public ?string $approval_phase,
        public ?string $request_ip,
        public ?string $user_agent,
    ) {}
}
