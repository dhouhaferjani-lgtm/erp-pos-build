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
     * Returns certifications whose `expires_at` falls within `$days` days from today,
     * scoped to the supplied `$tenantId`. Tenant-only scope is correct here:
     * the `workshop_technician_certifications` table has `tenant_id` only (no
     * `company_id`) — see migration `2026_04_19_120002_create_workshop_technician_certifications_table.php:22`.
     *
     * Consumed by `CheckExpiringCertifications` (Task 11) which iterates per tenant.
     *
     * @return Collection<int, TechnicianCertification>
     */
    public function findExpiringWithin(int $days, string $tenantId): Collection;
}
