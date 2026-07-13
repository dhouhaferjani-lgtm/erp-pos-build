<?php

declare(strict_types=1);

namespace Tests\Unit\Expense;

use App\Modules\Expense\Domain\Enums\RecurrenceFrequency;
use App\Modules\Expense\Domain\Services\RecurrenceCursor;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class RecurrenceCursorTest extends TestCase
{
    public function test_monthly_cursor_stays_anchored_to_day_31_after_february_clamp(): void
    {
        $start = CarbonImmutable::parse('2026-01-31');

        $february = RecurrenceCursor::next($start, RecurrenceFrequency::Monthly, $start);
        $march = RecurrenceCursor::next($start, RecurrenceFrequency::Monthly, $february);

        $this->assertSame('2026-02-28', $february->toDateString());
        $this->assertSame('2026-03-31', $march->toDateString());
    }

    public function test_quarterly_cursor_advances_three_months_from_origin(): void
    {
        $start = CarbonImmutable::parse('2026-02-15');

        $next = RecurrenceCursor::next($start, RecurrenceFrequency::Quarterly, $start);

        $this->assertSame('2026-05-15', $next->toDateString());
    }

    public function test_yearly_cursor_clamps_leap_day_without_overflow(): void
    {
        $start = CarbonImmutable::parse('2024-02-29');

        $next = RecurrenceCursor::next($start, RecurrenceFrequency::Yearly, $start);

        $this->assertSame('2025-02-28', $next->toDateString());
    }

    public function test_first_on_or_after_skips_past_monthly_occurrences(): void
    {
        $next = RecurrenceCursor::firstOnOrAfter(
            CarbonImmutable::parse('2026-01-05'),
            RecurrenceFrequency::Monthly,
            CarbonImmutable::parse('2026-04-20'),
        );

        $this->assertSame('2026-05-05', $next->toDateString());
    }

    public function test_first_on_or_after_returns_origin_when_today_is_before_or_on_origin(): void
    {
        $start = CarbonImmutable::parse('2026-01-05');

        $before = RecurrenceCursor::firstOnOrAfter(
            $start,
            RecurrenceFrequency::Monthly,
            CarbonImmutable::parse('2025-12-31'),
        );
        $onOrigin = RecurrenceCursor::firstOnOrAfter(
            $start,
            RecurrenceFrequency::Monthly,
            CarbonImmutable::parse('2026-01-05'),
        );

        $this->assertSame('2026-01-05', $before->toDateString());
        $this->assertSame('2026-01-05', $onOrigin->toDateString());
    }

    public function test_first_on_or_after_includes_an_exact_later_occurrence(): void
    {
        $next = RecurrenceCursor::firstOnOrAfter(
            CarbonImmutable::parse('2026-01-05'),
            RecurrenceFrequency::Monthly,
            CarbonImmutable::parse('2026-04-05'),
        );

        $this->assertSame('2026-04-05', $next->toDateString());
    }

    public function test_period_keys_follow_frequency_granularity(): void
    {
        $due = CarbonImmutable::parse('2026-07-15');

        $this->assertSame('2026-07', RecurrenceCursor::periodKey($due, RecurrenceFrequency::Monthly));
        $this->assertSame('2026-Q3', RecurrenceCursor::periodKey($due, RecurrenceFrequency::Quarterly));
        $this->assertSame('2026', RecurrenceCursor::periodKey($due, RecurrenceFrequency::Yearly));
    }
}
