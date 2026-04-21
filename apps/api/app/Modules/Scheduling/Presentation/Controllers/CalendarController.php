<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Scheduling\Application\Queries\DayAvailabilityQuery;
use App\Modules\Scheduling\Application\Queries\FreeSlotsQuery;
use App\Modules\Scheduling\Application\Queries\MonthSummaryQuery;
use App\Modules\Scheduling\Application\Queries\WeekViewQuery;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilityWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calendar read endpoints:
 *   GET /day?date=YYYY-MM-DD
 *   GET /week?week_start=YYYY-MM-DD
 *   GET /month?year=YYYY&month=M
 *   GET /free-slots?duration=N&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * All gated by `scheduling.appointments.view` (the query data feeds every
 * calendar surface). Responses pass through the underlying query shapes
 * unchanged — frontend types are generated via the query-result typedefs.
 */
final class CalendarController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly DayAvailabilityQuery $dayQuery,
        private readonly WeekViewQuery $weekQuery,
        private readonly MonthSummaryQuery $monthQuery,
        private readonly FreeSlotsQuery $freeSlotsQuery,
    ) {}

    public function day(Request $request): JsonResponse
    {
        $this->assertView($request);
        $companyId = $this->companyContext->requireCompanyId();

        $dateRaw = (string) $request->query('date', '');
        try {
            $date = new \DateTimeImmutable($dateRaw !== '' ? $dateRaw : 'today');
        } catch (\Exception) {
            return $this->invalidRange();
        }

        /** @var array<string, mixed> $result */
        $result = $this->dayQuery->run($companyId, $date);

        return response()->json(['data' => $this->serializeDay($result)]);
    }

    public function week(Request $request): JsonResponse
    {
        $this->assertView($request);
        $companyId = $this->companyContext->requireCompanyId();

        $startRaw = (string) $request->query('week_start', '');
        try {
            $weekStart = new \DateTimeImmutable($startRaw !== '' ? $startRaw : 'monday this week');
            $weekStart = $weekStart->setTime(0, 0, 0);
        } catch (\Exception) {
            return $this->invalidRange();
        }

        $data = $this->weekQuery->run($companyId, $weekStart);

        return response()->json(['data' => $this->serializeWeek($data)]);
    }

    public function month(Request $request): JsonResponse
    {
        $this->assertView($request);
        $companyId = $this->companyContext->requireCompanyId();

        $year = (int) $request->query('year', (string) (int) date('Y'));
        $month = (int) $request->query('month', (string) (int) date('n'));
        if ($month < 1 || $month > 12 || $year < 1970 || $year > 3000) {
            return $this->invalidRange();
        }

        $data = $this->monthQuery->run($companyId, $year, $month);

        return response()->json(['data' => array_values($data)]);
    }

    public function freeSlots(Request $request): JsonResponse
    {
        $this->assertView($request);
        $companyId = $this->companyContext->requireCompanyId();

        $duration = (int) $request->query('duration', '60');
        $fromRaw = (string) $request->query('from', '');
        $toRaw = (string) $request->query('to', '');
        if ($duration <= 0 || $fromRaw === '' || $toRaw === '') {
            return $this->invalidRange();
        }

        try {
            $from = (new \DateTimeImmutable($fromRaw))->setTime(0, 0, 0);
            $to = (new \DateTimeImmutable($toRaw))->setTime(0, 0, 0)->modify('+1 day');
        } catch (\Exception) {
            return $this->invalidRange();
        }

        if ($to <= $from) {
            return $this->invalidRange();
        }

        $slots = $this->freeSlotsQuery->find(
            companyId: $companyId,
            durationMinutes: $duration,
            earliestFrom: $from,
            latestUntil: $to,
        );

        /** @var list<array{bay_id: string, start: string, end: string, duration_minutes: int}> $serialized */
        $serialized = array_map(
            static fn (AvailabilityWindow $w): array => [
                // Staff endpoint — bay_id IS exposed here (unlike storefront).
                'bay_id' => $w->resource_type === AvailabilityWindow::TYPE_BAY ? $w->resource_id : '',
                'start' => $w->starts_at->format(\DateTimeInterface::ATOM),
                'end' => $w->ends_at->format(\DateTimeInterface::ATOM),
                'duration_minutes' => $w->durationMinutes(),
            ],
            $slots,
        );

        return response()->json(['data' => $serialized]);
    }

    private function assertView(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can('scheduling.appointments.view')) {
            abort(403);
        }
    }

    private function invalidRange(): JsonResponse
    {
        return response()->json([
            'message' => 'Invalid date range.',
            'error_code' => 'invalid_range',
        ], 422);
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private function serializeDay(array $day): array
    {
        /** @var array<string, list<AvailabilityWindow>> $availabilityByBay */
        $availabilityByBay = is_array($day['availability'] ?? null) ? $day['availability'] : [];
        $availability = [];
        foreach ($availabilityByBay as $bayId => $windows) {
            $availability[$bayId] = array_map(
                static fn (AvailabilityWindow $w): array => [
                    'start' => $w->starts_at->format(\DateTimeInterface::ATOM),
                    'end' => $w->ends_at->format(\DateTimeInterface::ATOM),
                    'duration_minutes' => $w->durationMinutes(),
                ],
                $windows,
            );
        }

        /** @var array<string, list<array{appointment_id: string, starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable, status: string}>> $bookedByBay */
        $bookedByBay = is_array($day['booked'] ?? null) ? $day['booked'] : [];
        $booked = [];
        foreach ($bookedByBay as $bayId => $rows) {
            $booked[$bayId] = array_map(
                static fn (array $row): array => [
                    'appointment_id' => $row['appointment_id'],
                    'start' => $row['starts_at']->format(\DateTimeInterface::ATOM),
                    'end' => $row['ends_at']->format(\DateTimeInterface::ATOM),
                    'status' => $row['status'],
                ],
                $rows,
            );
        }

        return [
            'date' => isset($day['date']) && is_string($day['date']) ? $day['date'] : '',
            'availability' => $availability,
            'booked' => $booked,
        ];
    }

    /**
     * @param  array<string, list<array{
     *     appointment_id: string, bay_id: ?string, primary_technician_profile_id: ?string,
     *     starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable, status: string,
     *     customer_display: ?string, appointment_number: string
     * }>>  $week
     * @return array<string, list<array<string, mixed>>>
     */
    private function serializeWeek(array $week): array
    {
        $out = [];
        foreach ($week as $date => $entries) {
            $out[$date] = array_map(
                static fn (array $entry): array => [
                    'appointment_id' => $entry['appointment_id'],
                    'bay_id' => $entry['bay_id'],
                    'primary_technician_profile_id' => $entry['primary_technician_profile_id'],
                    'start' => $entry['starts_at']->format(\DateTimeInterface::ATOM),
                    'end' => $entry['ends_at']->format(\DateTimeInterface::ATOM),
                    'status' => $entry['status'],
                    'customer_display' => $entry['customer_display'],
                    'appointment_number' => $entry['appointment_number'],
                ],
                $entries,
            );
        }

        return $out;
    }
}
