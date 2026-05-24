<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

final readonly class CashDrawerMovementPayload extends Phase4SimplePayload
{
    public const PAYLOAD_KEYS = [
        'approval_event_id',
        'approval_id',
        'approval_scope',
        'amount',
        'company_id',
        'event_time_device',
        'operation_type',
        'reason',
        'shift_id',
        'supervisor_user_id',
        'target_reference_id',
        'tenant_id',
        'terminal_id',
        'training_flag',
    ];

    public static function payloadKeys(): array
    {
        return self::PAYLOAD_KEYS;
    }
}
