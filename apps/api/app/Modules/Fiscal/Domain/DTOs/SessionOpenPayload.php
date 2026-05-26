<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

final readonly class SessionOpenPayload extends Phase4SimplePayload
{
    public const PAYLOAD_KEYS = [
        'business_date',
        'currency_code',
        'currency_scale',
        'opened_at_device',
        'opening_float_amount',
        'operator_id',
        'operator_name',
        'session_id',
        'shift_id',
        'terminal_id',
        'terminal_label',
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
