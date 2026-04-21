<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Infrastructure\Persistence;

use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianCertificationRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\TechnicianCertification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final readonly class EloquentTechnicianCertificationRepository implements TechnicianCertificationRepositoryInterface
{
    public function findById(string $id): ?TechnicianCertification
    {
        return TechnicianCertification::query()->find($id);
    }

    /**
     * @return Collection<int, TechnicianCertification>
     */
    public function forProfile(string $profileId): Collection
    {
        /** @var Collection<int, TechnicianCertification> $result */
        $result = TechnicianCertification::query()
            ->where('technician_profile_id', $profileId)
            // Null `expires_at` sorts last: emit 1 for null, 0 otherwise, then asc on the date.
            ->orderByRaw('(expires_at IS NULL) ASC')
            ->orderBy('expires_at', 'asc')
            ->get();

        return $result;
    }

    /**
     * Returns certs expiring within [today, today + $days] inclusive. Excludes already-expired
     * ones (< today) — those are surfaced through a different alerting surface.
     *
     * @return Collection<int, TechnicianCertification>
     */
    public function findExpiringWithin(int $days): Collection
    {
        $today = Carbon::today();
        $horizon = $today->copy()->addDays($days);

        /** @var Collection<int, TechnicianCertification> $result */
        $result = TechnicianCertification::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$today, $horizon])
            ->get();

        return $result;
    }
}
