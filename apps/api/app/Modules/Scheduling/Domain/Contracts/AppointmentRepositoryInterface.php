<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\Appointment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Persistence contract for the Appointment aggregate.
 *
 * Owned by Plan D (the Scheduling module) — callers in other modules may
 * reference appointments only through this interface. `findForUpdate`
 * acquires a row-level `FOR UPDATE` lock and MUST be used inside the
 * conversion / transition transactions to prevent race conditions.
 */
interface AppointmentRepositoryInterface
{
    public function findById(string $id): ?Appointment;

    /**
     * Acquire a row-level lock on the appointment for the duration of the
     * surrounding DB transaction. REQUIRED by
     * `AppointmentConversionService::convert` per Spec §7.2.
     *
     * @throws ModelNotFoundException
     */
    public function findForUpdate(string $id): Appointment;

    public function findByWorkOrderId(string $workOrderId): ?Appointment;

    /**
     * Overlap query used by `ConflictDetectionService` — returns every
     * active (non-cancelled / non-no-show / non-soft-deleted) appointment
     * whose window intersects `[$start, $end)` on the given bay. Pass
     * `$excludeAppointmentId` during a reschedule so the appointment
     * being moved does not conflict with itself.
     *
     * @return Collection<int, Appointment>
     */
    public function findOverlapping(
        string $bayId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?string $excludeAppointmentId = null,
    ): Collection;

    /**
     * Paginate appointments for a company — used by staff list views.
     *
     * Supported filters (all optional):
     *   - 'status' => AppointmentStatus enum
     *   - 'bay_id' => string (uuid)
     *   - 'date_from' => \DateTimeImmutable
     *   - 'date_to' => \DateTimeImmutable
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function paginate(string $companyId, array $filters = [], int $perPage = 25): LengthAwarePaginator;

    /**
     * @return Collection<int, Appointment>
     */
    public function findByCompanyInDateRange(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): Collection;

    public function save(Appointment $appointment): void;
}
