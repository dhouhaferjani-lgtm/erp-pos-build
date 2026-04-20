<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\ScheduleConfig;

/**
 * Persistence contract for the ScheduleConfig aggregate. One row per
 * (tenant, company, location).
 */
interface ScheduleConfigRepositoryInterface
{
    public function findById(string $id): ?ScheduleConfig;

    public function findForLocation(string $tenantId, string $companyId, string $locationId): ?ScheduleConfig;

    public function save(ScheduleConfig $config): ScheduleConfig;
}
