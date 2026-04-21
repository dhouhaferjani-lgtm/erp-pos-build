<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Single payroll-handoff row — one per (technician, week). Monetary fields are
 * `CurrencyScale::bcformat`-formatted strings; consumers MUST NOT cast to float.
 *
 * Pay gating is applied via `toArray()` override (consistent with
 * `TechnicianProfileData` and `WeeklyHoursSummaryData`).
 */
#[TypeScript]
final class PayrollExportLineData extends Data
{
    public function __construct(
        public string $technician_profile_id,
        public string $user_display_name,
        public string $employee_code,
        public string $week_starts_at,
        public int $total_minutes,
        public int $work_order_minutes,
        public int $overtime_minutes,
        /** @pay */
        public ?string $estimated_billable_amount,
        /** @pay */
        public ?string $estimated_cost_amount,
        public string $currency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $user = auth()->user();

        if ($user === null || ! $user->can('workshop.technicians.view_pay')) {
            unset($data['estimated_billable_amount'], $data['estimated_cost_amount']);
        }

        return $data;
    }
}
