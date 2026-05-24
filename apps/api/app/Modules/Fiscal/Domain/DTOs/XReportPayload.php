<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

final readonly class XReportPayload extends Phase4SimplePayload
{
    public const PAYLOAD_KEYS = [
        'business_date',
        'cash_drawer_totals',
        'generated_at_device',
        'operational_event_range',
        'operator_id',
        'operator_name',
        'payment_method_totals',
        'period_end',
        'period_start',
        'receipt_count',
        'refunds_totals',
        'sales_totals',
        'session_id',
        'shift_id',
        'terminal_id',
        'training_flag',
        'vat_breakdown',
        'voids_totals',
        'x_report_uuid',
    ];

    /**
     * @return list<string>
     */
    public static function payloadKeys(): array
    {
        return self::PAYLOAD_KEYS;
    }
}
