<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Infrastructure\Persistence;

use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianTimeOffRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeOff;
use Illuminate\Support\Collection;

final readonly class EloquentTechnicianTimeOffRepository implements TechnicianTimeOffRepositoryInterface
{
    public function findById(string $id): ?TechnicianTimeOff
    {
        return TechnicianTimeOff::query()->find($id);
    }

    /**
     * Half-open interval intersection: overlap iff `leave.starts_at < to && leave.ends_at > from`.
     *
     * @return Collection<int, TechnicianTimeOff>
     */
    public function findOverlappingApproved(
        string $profileId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): Collection {
        /** @var Collection<int, TechnicianTimeOff> $result */
        $result = TechnicianTimeOff::query()
            ->where('technician_profile_id', $profileId)
            ->where('is_approved', true)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get();

        return $result;
    }
}
