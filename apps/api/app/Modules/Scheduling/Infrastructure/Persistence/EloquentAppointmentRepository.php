<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Eloquent implementation of {@see AppointmentRepositoryInterface}.
 *
 * `findForUpdate` issues `SELECT ... FOR UPDATE`; the caller MUST be
 * inside a `DB::transaction(...)` — Laravel silently downgrades
 * `lockForUpdate()` outside a transaction which defeats the purpose.
 */
final class EloquentAppointmentRepository implements AppointmentRepositoryInterface
{
    public function findById(string $id): ?Appointment
    {
        return Appointment::query()->find($id);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function findForUpdate(string $id): Appointment
    {
        $appointment = Appointment::query()->whereKey($id)->lockForUpdate()->first();

        if ($appointment === null) {
            throw (new ModelNotFoundException)->setModel(Appointment::class, [$id]);
        }

        return $appointment;
    }

    public function findByWorkOrderId(string $workOrderId): ?Appointment
    {
        return Appointment::query()->where('work_order_id', $workOrderId)->first();
    }

    public function findOverlapping(
        string $bayId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?string $excludeAppointmentId = null,
    ): Collection {
        $query = Appointment::query()
            ->where('bay_id', $bayId)
            ->whereNotIn('status', [
                AppointmentStatus::Cancelled->value,
                AppointmentStatus::NoShow->value,
            ])
            // Half-open [start, end) overlap: existing.start < end AND existing.end > start
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start);

        if ($excludeAppointmentId !== null) {
            $query->where('id', '!=', $excludeAppointmentId);
        }

        /** @var Collection<int, Appointment> $result */
        $result = $query->get();

        return $result;
    }

    public function paginate(string $companyId, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = Appointment::query()->where('company_id', $companyId);

        if (isset($filters['status']) && $filters['status'] instanceof AppointmentStatus) {
            $query->where('status', $filters['status']->value);
        }
        if (isset($filters['bay_id']) && is_string($filters['bay_id'])) {
            $query->where('bay_id', $filters['bay_id']);
        }
        if (isset($filters['date_from']) && $filters['date_from'] instanceof \DateTimeInterface) {
            $query->where('scheduled_start', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to']) && $filters['date_to'] instanceof \DateTimeInterface) {
            $query->where('scheduled_start', '<', $filters['date_to']);
        }

        /** @var LengthAwarePaginator<int, Appointment> $page */
        $page = $query->orderBy('scheduled_start')->paginate($perPage);

        return $page;
    }

    public function findByCompanyInDateRange(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): Collection {
        /** @var Collection<int, Appointment> $result */
        $result = Appointment::query()
            ->where('company_id', $companyId)
            ->where('scheduled_start', '>=', $from)
            ->where('scheduled_start', '<', $to)
            ->orderBy('scheduled_start')
            ->get();

        return $result;
    }

    public function save(Appointment $appointment): void
    {
        $appointment->save();
    }
}
