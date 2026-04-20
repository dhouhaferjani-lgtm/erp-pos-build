<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Contracts;

use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use Illuminate\Support\Collection;

interface TechnicianTimeEntryRepositoryInterface
{
    public function findOpenForTechnician(string $profileId): ?TechnicianTimeEntry;

    public function findOpenForWorkOrder(string $workOrderId): ?TechnicianTimeEntry;

    public function findMostRecentlyClosedForWorkOrder(string $workOrderId): ?TechnicianTimeEntry;

    /**
     * @return Collection<int, TechnicianTimeEntry>
     */
    public function inRangeForProfile(
        string $profileId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): Collection;
}
