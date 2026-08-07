<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

use Spatie\LaravelData\Data;

final class SupportAccessNotificationData extends Data
{
    public function __construct(
        public string $grant_id,
        public string $tenant_id,
        public string $event,
        public string $ticket_ref,
    ) {}
}
