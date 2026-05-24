<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

final readonly class OperatorApprovalGrantedPayload extends Phase4SimplePayload
{
    public const PAYLOAD_KEYS = [
        'approval_id',
        'approval_scope',
        'cashier_user_id',
        'company_id',
        'event_time_device',
        'policy_version',
        'reason_code',
        'reason_text',
        'regime_extensions',
        'requested_at_device',
        'resolved_at_device',
        'supervisor_user_id',
        'supervisor_user_snapshot',
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
