<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Technician\Application\DTOs\TechnicianTimeOffData;
use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianProfileRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\Enums\TimeOffReason;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeOff;
use App\Modules\Workshop\Technician\Presentation\Requests\StoreTimeOffRequest;
use App\Modules\Workshop\Technician\Presentation\Requests\UpdateTimeOffRequest;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CRUD controller for TechnicianTimeOff rows.
 *
 * Authorization:
 *  - reads (`index`) require `workshop.technicians.view`; a technician may also
 *    view their OWN time-off even without manage permission (self-view path).
 *  - writes require `workshop.technicians.manage_time_off`.
 *
 * Validation:
 *  - `reason_code` must be a valid TimeOffReason enum value.
 *  - `ends_at` must be strictly after `starts_at` (DB has a matching CHECK constraint).
 *  - On store, we explicitly reject overlaps with any existing time-off row for
 *    the same technician; the response uses error code `TIME_OFF_OVERLAP` so the
 *    UI can surface a targeted message.
 */
final class TechnicianTimeOffController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TechnicianProfileRepositoryInterface $profiles,
    ) {}

    public function index(Request $request, string $technicianId): JsonResponse
    {
        if (! Str::isUuid($technicianId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        if ($profile === null) {
            abort(404);
        }

        $user = $request->user();
        $isOwner = $user !== null && $profile->user_id === $user->getAuthIdentifier();
        $canView = $user !== null && $user->can('workshop.technicians.view');
        if (! $isOwner && ! $canView) {
            abort(403);
        }
        if (! $isOwner) {
            $companyId = $this->companyContext->requireCompanyId();
            if ($profile->company_id !== $companyId) {
                abort(404);
            }
        }

        $query = TechnicianTimeOff::query()
            ->where('technician_profile_id', $technicianId)
            ->orderByDesc('starts_at');

        $from = $request->query('from');
        if (is_string($from) && $from !== '') {
            $query->where('ends_at', '>=', $from);
        }
        $to = $request->query('to');
        if (is_string($to) && $to !== '') {
            $query->where('starts_at', '<=', $to.' 23:59:59');
        }

        $rows = $query->get();

        /** @var list<array<string, mixed>> $data */
        $data = $rows
            ->map(static fn (TechnicianTimeOff $r): array => TechnicianTimeOffData::fromModel($r)->toArray())
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }

    public function store(StoreTimeOffRequest $request, string $technicianId): JsonResponse
    {
        if (! Str::isUuid($technicianId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        $companyId = $this->companyContext->requireCompanyId();
        if ($profile === null || $profile->company_id !== $companyId) {
            abort(404);
        }

        /** @var array{reason_code: string, starts_at: string, ends_at: string, is_full_day?: bool, notes?: string|null} $validated */
        $validated = $request->validated();

        $startsAt = new DateTimeImmutable($validated['starts_at']);
        $endsAt = new DateTimeImmutable($validated['ends_at']);

        if ($this->hasOverlap($technicianId, $startsAt, $endsAt)) {
            return response()->json([
                'error' => [
                    'code' => 'TIME_OFF_OVERLAP',
                    'message' => 'This time-off window overlaps an existing entry.',
                ],
            ], 422);
        }

        $row = TechnicianTimeOff::query()->create([
            'tenant_id' => $profile->tenant_id,
            'technician_profile_id' => $profile->id,
            'reason_code' => TimeOffReason::from($validated['reason_code']),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_full_day' => (bool) ($validated['is_full_day'] ?? true),
            'notes' => $validated['notes'] ?? null,
            'is_approved' => false,
        ]);

        return response()->json(
            ['data' => TechnicianTimeOffData::fromModel($row)->toArray()],
            201,
        );
    }

    public function update(
        UpdateTimeOffRequest $request,
        string $technicianId,
        string $timeOffId,
    ): JsonResponse {
        if (! Str::isUuid($technicianId) || ! Str::isUuid($timeOffId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        $companyId = $this->companyContext->requireCompanyId();
        if ($profile === null || $profile->company_id !== $companyId) {
            abort(404);
        }

        /** @var TechnicianTimeOff|null $row */
        $row = TechnicianTimeOff::query()
            ->where('technician_profile_id', $technicianId)
            ->where('id', $timeOffId)
            ->first();
        if ($row === null) {
            abort(404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        if (isset($validated['reason_code']) && is_string($validated['reason_code'])) {
            $row->reason_code = TimeOffReason::from($validated['reason_code']);
            unset($validated['reason_code']);
        }

        $row->fill($validated);
        $row->save();

        return response()->json([
            'data' => TechnicianTimeOffData::fromModel($row->refresh())->toArray(),
        ]);
    }

    public function destroy(string $technicianId, string $timeOffId): JsonResponse
    {
        if (! Str::isUuid($technicianId) || ! Str::isUuid($timeOffId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        $companyId = $this->companyContext->requireCompanyId();
        if ($profile === null || $profile->company_id !== $companyId) {
            abort(404);
        }

        $deleted = TechnicianTimeOff::query()
            ->where('technician_profile_id', $technicianId)
            ->where('id', $timeOffId)
            ->delete();

        if ($deleted === 0) {
            abort(404);
        }

        return response()->json(null, 204);
    }

    private function hasOverlap(
        string $technicianId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $excludeId = null,
    ): bool {
        $query = TechnicianTimeOff::query()
            ->where('technician_profile_id', $technicianId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}
