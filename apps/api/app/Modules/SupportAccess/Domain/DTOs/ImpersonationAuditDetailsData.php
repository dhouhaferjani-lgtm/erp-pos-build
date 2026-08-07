<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\DTOs;

use Spatie\LaravelData\Data;

final class ImpersonationAuditDetailsData extends Data
{
    public function __construct(
        public ?string $ticket_ref,
        public ?string $route_name,
        public ?int $response_status,
        public ?string $error_code,
        public ?string $resource_type,
        public ?string $resource_id,
        public ?string $grant_chain_head = null,
        public ?string $request_ip = null,
        public ?string $user_agent = null,
    ) {}
}
