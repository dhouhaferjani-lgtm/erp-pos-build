<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

final readonly class ZReportPayload extends Phase4SimplePayload
{
    public const PAYLOAD_KEYS = [
        'business_date',
        'cash_count',
        'cash_drawer_totals',
        'closed_at_device',
        'company_snapshot',
        'currency_code',
        'currency_scale',
        'formatted_z_number',
        'grand_totals_after',
        'grand_totals_before',
        'legacy_report_reference',
        'operational_event_range',
        'operator_id',
        'operator_name',
        'payment_method_totals',
        'period_end',
        'period_start',
        'period_type',
        'receipt_totals',
        'refunds_totals',
        'seller',
        'session_event_range',
        'session_id',
        'shift_id',
        'terminal_id',
        'terminal_label',
        'tolerance_summary',
        'training_flag',
        'vat_breakdown',
        'voids_totals',
        'z_number',
        'z_report_uuid',
    ];

    /**
     * @return list<string>
     */
    public static function payloadKeys(): array
    {
        return self::PAYLOAD_KEYS;
    }
}
