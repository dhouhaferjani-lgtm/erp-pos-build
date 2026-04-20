<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\DTOs;

use App\Modules\Workshop\Technician\Domain\ValueObjects\WeeklyHoursSummary;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class WeeklyHoursSummaryData extends Data
{
    /**
     * @param  array<string, int>  $minutes_by_entry_type
     * @param  DataCollection<int, WorkOrderHoursBreakdownData>  $work_order_breakdown
     */
    public function __construct(
        public string $technician_profile_id,
        public string $week_starts_at,
        public int $total_minutes,
        public array $minutes_by_entry_type,
        #[DataCollectionOf(WorkOrderHoursBreakdownData::class)]
        public DataCollection $work_order_breakdown,
        /** @pay */
        public ?string $estimated_billable_amount,
        /** @pay */
        public ?string $estimated_cost_amount,
        public int $overtime_minutes,
        public string $currency,
    ) {}

    public static function fromSummary(WeeklyHoursSummary $s): self
    {
        $breakdown = WorkOrderHoursBreakdownData::collect(
            array_map(
                static fn (array $line): WorkOrderHoursBreakdownData => new WorkOrderHoursBreakdownData(
                    work_order_id: $line['work_order_id'],
                    minutes: $line['minutes'],
                ),
                $s->work_order_breakdown,
            ),
            DataCollection::class,
        );

        return new self(
            technician_profile_id: $s->technician_profile_id,
            week_starts_at: $s->week_starts_at,
            total_minutes: $s->total_minutes,
            minutes_by_entry_type: $s->minutes_by_entry_type,
            work_order_breakdown: $breakdown,
            estimated_billable_amount: $s->estimated_billable_amount,
            estimated_cost_amount: $s->estimated_cost_amount,
            overtime_minutes: $s->overtime_minutes,
            currency: $s->currency,
        );
    }

    /**
     * Mask pay amounts when the user lacks `workshop.technicians.view_pay`.
     *
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
