<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Contracts;

use App\Modules\Workshop\Technician\Domain\TechnicianCertification;
use Illuminate\Support\Collection;

interface TechnicianCertificationRepositoryInterface
{
    public function findById(string $id): ?TechnicianCertification;

    /**
     * @return Collection<int, TechnicianCertification>
     */
    public function forProfile(string $profileId): Collection;

    /**
     * Returns certifications whose `expires_at` falls within `$days` days from today.
     * Consumed by `CheckExpiringCertifications` (Task 11).
     *
     * @return Collection<int, TechnicianCertification>
     */
    public function findExpiringWithin(int $days): Collection;
}
