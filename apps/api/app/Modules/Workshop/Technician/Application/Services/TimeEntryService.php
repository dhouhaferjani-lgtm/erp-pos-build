<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\Services;

use App\Modules\Workshop\Technician\Domain\Enums\TimeEntrySource;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Modules\Workshop\Technician\Domain\Events\TechnicianTimeEntryClosed;
use App\Modules\Workshop\Technician\Domain\Events\TechnicianTimeEntryStarted;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Opens, closes, and reopens technician time entries. Called from WorkOrder event listeners
 * (Plan B) and from manual endpoints (Task 12). Emits domain events on each state transition.
 *
 * Uniqueness: PostgreSQL partial index `uq_wtte_open` guarantees at most one open entry per
 * technician at any given time. Second concurrent `start` surfaces a QueryException.
 */
final readonly class TimeEntryService
{
    public function __construct(
        private Dispatcher $events,
        private Connection $connection,
    ) {}

    /**
     * Opens a new WorkOrder-typed time entry for the given profile. Emits
     * `TechnicianTimeEntryStarted`. If an open entry already exists for this tech,
     * the database unique index (`uq_wtte_open`) will throw.
     */
    public function start(string $profileId, string $workOrderId, \DateTimeImmutable $startedAt): TechnicianTimeEntry
    {
        $profile = TechnicianProfile::query()->findOrFail($profileId);

        // Enforce "one open entry per technician" in the application layer. PostgreSQL backs
        // this up with a partial unique index (`uq_wtte_open`); SQLite does not support
        // partial indexes, so we raise the same kind of QueryException here to keep contract
        // parity across drivers.
        $existingOpen = TechnicianTimeEntry::query()
            ->where('technician_profile_id', $profile->id)
            ->whereNull('ended_at')
            ->exists();
        if ($existingOpen) {
            throw new QueryException(
                (string) ($this->connection->getName() ?? 'default'),
                'INSERT INTO workshop_technician_time_entries',
                [],
                new \RuntimeException(
                    'Technician already has an open time entry (uq_wtte_open violation).',
                ),
            );
        }

        $entry = new TechnicianTimeEntry;
        $entry->id = (string) Str::uuid();
        $entry->tenant_id = $profile->tenant_id;
        $entry->company_id = $profile->company_id;
        $entry->technician_profile_id = $profile->id;
        $entry->started_at = Carbon::instance(\DateTime::createFromImmutable($startedAt));
        $entry->ended_at = null;
        $entry->duration_minutes = null;
        $entry->entry_type = TimeEntryType::WorkOrder;
        $entry->work_order_id = $workOrderId;
        $entry->source = TimeEntrySource::Event;
        $entry->save();

        $this->events->dispatch(new TechnicianTimeEntryStarted(
            $entry->id,
            $profile->id,
            TimeEntryType::WorkOrder,
            $workOrderId,
            $startedAt,
        ));

        return $entry->fresh() ?? $entry;
    }

    /**
     * Closes the open entry for the given work order (if any). Computes duration,
     * emits `TechnicianTimeEntryClosed`. No-op (returns null) if no open entry exists
     * — listeners should silently accept this case.
     */
    public function closeForWorkOrder(string $workOrderId, \DateTimeImmutable $endedAt): ?TechnicianTimeEntry
    {
        $entry = TechnicianTimeEntry::query()
            ->where('work_order_id', $workOrderId)
            ->whereNull('ended_at')
            ->first();

        if (! $entry instanceof TechnicianTimeEntry) {
            return null;
        }

        return $this->closeEntry($entry, $endedAt);
    }

    /**
     * Reopens the most recently closed entry for the given work order by nulling
     * `ended_at` and `duration_minutes`. Does not create a new row. No-op if the
     * work order has no closed entries.
     */
    public function reopenMostRecentlyClosedForWorkOrder(string $workOrderId, \DateTimeImmutable $resumedAt): ?TechnicianTimeEntry
    {
        unset($resumedAt); // Signature retained for future compatibility; currently unused.

        $entry = TechnicianTimeEntry::query()
            ->where('work_order_id', $workOrderId)
            ->whereNotNull('ended_at')
            ->orderByDesc('ended_at')
            ->first();

        if (! $entry instanceof TechnicianTimeEntry) {
            return null;
        }

        $entry->ended_at = null;
        $entry->duration_minutes = null;
        $entry->save();

        return $entry->fresh() ?? $entry;
    }

    private function closeEntry(TechnicianTimeEntry $entry, \DateTimeImmutable $endedAt): TechnicianTimeEntry
    {
        $startedAtImmutable = $entry->started_at->copy()->setTimezone('UTC')->toDateTimeImmutable();
        $endedAtUtc = $endedAt->setTimezone(new \DateTimeZone('UTC'));

        $seconds = $endedAtUtc->getTimestamp() - $startedAtImmutable->getTimestamp();
        $duration = max(0, (int) floor($seconds / 60));

        $entry->ended_at = Carbon::instance(\DateTime::createFromImmutable($endedAt));
        $entry->duration_minutes = $duration;
        $entry->save();

        $this->events->dispatch(new TechnicianTimeEntryClosed(
            $entry->id,
            $entry->technician_profile_id,
            $duration,
            $endedAtUtc,
        ));

        return $entry->fresh() ?? $entry;
    }
}
