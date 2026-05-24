<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

final readonly class SessionClosePayload extends Phase4SimplePayload
{
    public const PAYLOAD_KEYS = [
        'business_date',
        'cash_count_lines',
        'cash_drawer_totals',
        'closure_status',
        'counted_cash',
        'expected_cash',
        'generated_at_device',
        'manager_approval',
        'operational_event_range',
        'operator_id',
        'operator_name',
        'payment_method_totals',
        'period_end',
        'period_start',
        'receipt_count',
        'refunds_totals',
        'sales_totals',
        'session_close_uuid',
        'session_id',
        'shift_id',
        'terminal_id',
        'training_flag',
        'variance_amount',
        'variance_direction',
        'variance_reason',
        'variance_severity',
        'vat_breakdown',
        'voids_totals',
    ];

    /**
     * @return list<string>
     */
    public static function payloadKeys(): array
    {
        return self::PAYLOAD_KEYS;
    }
}
