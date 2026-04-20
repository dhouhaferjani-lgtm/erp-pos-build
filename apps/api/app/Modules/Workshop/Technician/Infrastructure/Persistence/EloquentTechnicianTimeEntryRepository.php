<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Infrastructure\Persistence;

use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianTimeEntryRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use Illuminate\Support\Collection;

final readonly class EloquentTechnicianTimeEntryRepository implements TechnicianTimeEntryRepositoryInterface
{
    public function findOpenForTechnician(string $profileId): ?TechnicianTimeEntry
    {
        return TechnicianTimeEntry::query()
            ->where('technician_profile_id', $profileId)
            ->whereNull('ended_at')
            ->first();
    }

    public function findOpenForWorkOrder(string $workOrderId): ?TechnicianTimeEntry
    {
        return TechnicianTimeEntry::query()
            ->where('work_order_id', $workOrderId)
            ->whereNull('ended_at')
            ->first();
    }

    public function findMostRecentlyClosedForWorkOrder(string $workOrderId): ?TechnicianTimeEntry
    {
        return TechnicianTimeEntry::query()
            ->where('work_order_id', $workOrderId)
            ->whereNotNull('ended_at')
            ->orderByDesc('ended_at')
            ->first();
    }

    /**
     * @return Collection<int, TechnicianTimeEntry>
     */
    public function inRangeForProfile(
        string $profileId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): Collection {
        /** @var Collection<int, TechnicianTimeEntry> $result */
        $result = TechnicianTimeEntry::query()
            ->where('technician_profile_id', $profileId)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $to)
            ->orderBy('started_at')
            ->get();

        return $result;
    }
}
