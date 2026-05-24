<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

final readonly class AccountStatusChangedPayload extends Phase4SimplePayload
{
    public const PAYLOAD_KEYS = [
        'actor_user_id',
        'company_id',
        'event_time_device',
        'new_status',
        'old_status',
        'partner_id',
        'partner_snapshot',
        'reason',
        'status_version',
        'tenant_id',
        'terminal_id',
        'training_flag',
    ];

    public static function payloadKeys(): array
    {
        return self::PAYLOAD_KEYS;
    }
}
