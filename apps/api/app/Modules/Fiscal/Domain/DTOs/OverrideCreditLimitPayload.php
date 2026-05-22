<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

readonly class OverrideCreditLimitPayload extends Phase4SimplePayload
{
    public const PAYLOAD_KEYS = [
        'approval_event_id',
        'approval_id',
        'approval_scope',
        'company_id',
        'event_time_device',
        'override_context',
        'policy_version',
        'reason_code',
        'reason_text',
        'supervisor_user_id',
        'target',
        'tenant_id',
        'terminal_id',
        'training_flag',
    ];

    public static function payloadKeys(): array
    {
        return self::PAYLOAD_KEYS;
    }
}
