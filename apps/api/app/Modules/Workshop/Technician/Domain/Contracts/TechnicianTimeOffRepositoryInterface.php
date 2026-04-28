<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Contracts;

use App\Modules\Workshop\Technician\Domain\TechnicianTimeOff;
use Illuminate\Support\Collection;

interface TechnicianTimeOffRepositoryInterface
{
    public function findById(string $id): ?TechnicianTimeOff;

    /**
     * @return Collection<int, TechnicianTimeOff>
     */
    public function findOverlappingApproved(
        string $profileId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): Collection;
}
