<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

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
    ) {}
}
