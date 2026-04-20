<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\Services;

use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use App\Modules\Workshop\Technician\Domain\ValueObjects\WeeklyHoursSummary;
use App\Modules\Workshop\Technician\Domain\ValueObjects\WeeklySchedule;
use App\Shared\Domain\CurrencyScale;

/**
 * Computes a payroll-handoff summary of a technician's hours across a single ISO week.
 *
 * Monetary values (`estimated_billable_amount`, `estimated_cost_amount`) are emitted as
 * formatted strings via `CurrencyScale::bcformat(..., CurrencyScale::for($currency))` —
 * NEVER as floats. Consumers that need to sum across weeks must do so with bcmath.
 *
 * Overtime is computed as `max(0, totalMinutes - weeklySchedule.totalMinutesPerWeek())`.
 * It is a soft, advisory figure — not a legal determination.
 */
final readonly class WeeklyHoursService
{
    public function computeWeek(string $profileId, \DateTimeImmutable $weekStartsAt): WeeklyHoursSummary
    {
        $profile = TechnicianProfile::query()
            ->with('company:id,timezone')
            ->findOrFail($profileId);

        $weekStartUtc = $weekStartsAt->setTimezone(new \DateTimeZone('UTC'));
        $weekEndUtc = $weekStartUtc->modify('+7 days');

        /** @var list<TechnicianTimeEntry> $entries */
        $entries = TechnicianTimeEntry::query()
            ->where('technician_profile_id', $profile->id)
            ->where('started_at', '>=', $weekStartUtc)
            ->where('started_at', '<', $weekEndUtc)
            ->whereNotNull('duration_minutes')
            ->get()
            ->all();

        $totalMinutes = 0;
        $minutesByType = [];
        /** @var array<string, int> $minutesPerWo */
        $minutesPerWo = [];
        $workOrderMinutes = 0;

        foreach ($entries as $entry) {
            $duration = $entry->duration_minutes ?? 0;
            $totalMinutes += $duration;

            $typeKey = $entry->entry_type->value;
            $minutesByType[$typeKey] = ($minutesByType[$typeKey] ?? 0) + $duration;

            if ($entry->entry_type === TimeEntryType::WorkOrder) {
                $workOrderMinutes += $duration;
                if ($entry->work_order_id !== null) {
                    $woId = $entry->work_order_id;
                    $minutesPerWo[$woId] = ($minutesPerWo[$woId] ?? 0) + $duration;
                }
            }
        }

        $breakdown = [];
        foreach ($minutesPerWo as $woId => $mins) {
            $breakdown[] = ['work_order_id' => $woId, 'minutes' => $mins];
        }

        $currency = $profile->currency;
        $scale = CurrencyScale::for($currency);

        // Billable/cost totals: minutes_for_work_orders * hourly_rate / 60
        $billable = $this->computeAmount($workOrderMinutes, $profile->hourly_billing_rate, $scale);
        $cost = $this->computeAmount($workOrderMinutes, $profile->hourly_cost_rate, $scale);

        // Overtime vs configured schedule
        $schedule = WeeklySchedule::fromJson($profile->weekly_schedule, $profile->company->timezone);
        $scheduledMinutes = $schedule->totalMinutesPerWeek();
        $overtime = max(0, $totalMinutes - $scheduledMinutes);

        return new WeeklyHoursSummary(
            technician_profile_id: $profile->id,
            week_starts_at: $weekStartsAt->format(\DateTimeInterface::ATOM),
            total_minutes: $totalMinutes,
            minutes_by_entry_type: $minutesByType,
            work_order_breakdown: $breakdown,
            estimated_billable_amount: $billable,
            estimated_cost_amount: $cost,
            overtime_minutes: $overtime,
            currency: $currency,
        );
    }

    /**
     * @return numeric-string Formatted monetary amount; "0.00" when no rate is configured.
     */
    private function computeAmount(int $minutes, ?string $hourlyRate, int $scale): string
    {
        if ($hourlyRate === null) {
            return CurrencyScale::bcformat('0', $scale);
        }

        // amount = (minutes * hourlyRate) / 60
        // Multiply FIRST to avoid the truncation that bcdiv($hourlyRate, 60) introduces
        // when the per-minute rate is non-terminating (e.g. 25/60 = 0.41666… truncated
        // to 0.416 drops 0.01 on a 2h bill).
        $internalScale = max($scale + 6, 10);
        /** @phpstan-ignore argument.type */
        $numerator = bcmul((string) $minutes, $hourlyRate, $internalScale);
        $total = bcdiv($numerator, '60', $internalScale);

        return CurrencyScale::bcformat($total, $scale);
    }
}
