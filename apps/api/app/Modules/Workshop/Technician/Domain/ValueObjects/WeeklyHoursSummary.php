<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\ValueObjects;

/**
 * Read-only projection of a technician's hours for a single ISO week.
 *
 * Shape:
 *  - `minutes_by_entry_type` keyed by `TimeEntryType::value`.
 *  - `work_order_breakdown` is a list of `{work_order_id: string, minutes: int}` rows,
 *    one per distinct work order touched in the week.
 *  - `estimated_billable_amount` / `estimated_cost_amount` are formatted numeric strings
 *    using `CurrencyScale::bcformat($value, CurrencyScale::for($currency))`. Consumers
 *    should NOT cast to float.
 */
final readonly class WeeklyHoursSummary
{
    /**
     * @param  array<string, int>  $minutes_by_entry_type
     * @param  list<array{work_order_id: string, minutes: int}>  $work_order_breakdown
     */
    public function __construct(
        public string $technician_profile_id,
        public string $week_starts_at,
        public int $total_minutes,
        public array $minutes_by_entry_type,
        public array $work_order_breakdown,
        public string $estimated_billable_amount,
        public string $estimated_cost_amount,
        public int $overtime_minutes,
        public string $currency,
    ) {}
}
