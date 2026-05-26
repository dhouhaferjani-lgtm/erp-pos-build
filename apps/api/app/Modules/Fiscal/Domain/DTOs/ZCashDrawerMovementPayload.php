<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

final readonly class ZCashDrawerMovementPayload extends Phase4SimplePayload
{
    public const PAYLOAD_KEYS = [
        'amount',
        'approval',
        'business_date',
        'cash_drawer_operation_id',
        'currency_code',
        'currency_scale',
        'event_time_device',
        'movement_id',
        'movement_type',
        'operator_id',
        'operator_name',
        'reason_code',
        'reason_text',
        'session_id',
        'shift_id',
        'training_flag',
    ];

    /**
     * @return list<string>
     */
    public static function payloadKeys(): array
    {
        return self::PAYLOAD_KEYS;
    }
}
