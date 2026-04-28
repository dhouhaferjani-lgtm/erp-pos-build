<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Domain\Company;
use App\Modules\Scheduling\Application\Queries\FreeSlotsQuery;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilityWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public storefront endpoint — returns a list of bookable time windows for
 * a given company over a date range.
 *
 * Response is deliberately stripped of internal resource identifiers:
 * `bay_id`, `primary_technician_profile_id`, and any other scheduling-side
 * context is never exposed. The public client receives a flat list of
 * `{start, end, duration_minutes}` triples so the booking funnel cannot be
 * used to enumerate staff or infrastructure.
 *
 * The route parameter `{company_id}` is a `companies.uuid`; controllers
 * reject non-UUID values with 404 before any DB work is done.
 */
final class StorefrontAvailabilityController extends Controller
{
    /** Maximum search window (days) to cap single-shot enumeration. */
    private const MAX_RANGE_DAYS = 60;

    public function __construct(
        private readonly FreeSlotsQuery $freeSlots,
    ) {}

    public function index(Request $request, string $company_id): JsonResponse
    {
        if (! Str::isUuid($company_id)) {
            abort(404);
        }

        $company = Company::query()->find($company_id);
        if ($company === null) {
            abort(404);
        }

        $duration = (int) $request->query('duration', '60');
        if ($duration <= 0) {
            return response()->json([
                'message' => 'duration must be a positive integer (minutes).',
                'error_code' => 'invalid_duration',
            ], 422);
        }

        $fromRaw = $request->query('from');
        $toRaw = $request->query('to');
        if (! is_string($fromRaw) || ! is_string($toRaw)) {
            return response()->json([
                'message' => "Query parameters 'from' and 'to' are required (YYYY-MM-DD).",
                'error_code' => 'range_required',
            ], 422);
        }

        try {
            $from = (new \DateTimeImmutable($fromRaw))->setTime(0, 0, 0);
            $to = (new \DateTimeImmutable($toRaw))->setTime(0, 0, 0)->modify('+1 day');
        } catch (\Exception) {
            return response()->json([
                'message' => "Parameters 'from' and 'to' must be ISO-8601 dates.",
                'error_code' => 'invalid_range',
            ], 422);
        }

        if ($to <= $from) {
            return response()->json([
                'message' => "'to' must be strictly after 'from'.",
                'error_code' => 'invalid_range',
            ], 422);
        }

        $rangeDays = (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 86400);
        if ($rangeDays > self::MAX_RANGE_DAYS) {
            return response()->json([
                'message' => 'Requested range exceeds the maximum of '.self::MAX_RANGE_DAYS.' days.',
                'error_code' => 'range_too_wide',
            ], 422);
        }

        $slots = $this->freeSlots->find(
            companyId: $company->id,
            durationMinutes: $duration,
            earliestFrom: $from,
            latestUntil: $to,
        );

        /** @var list<array{start: string, end: string, duration_minutes: int}> $publicSlots */
        $publicSlots = array_map(
            static fn (AvailabilityWindow $w): array => [
                'start' => $w->starts_at->format(\DateTimeInterface::ATOM),
                'end' => $w->ends_at->format(\DateTimeInterface::ATOM),
                'duration_minutes' => $w->durationMinutes(),
            ],
            $slots,
        );

        return response()->json([
            'data' => $publicSlots,
        ]);
    }
}
