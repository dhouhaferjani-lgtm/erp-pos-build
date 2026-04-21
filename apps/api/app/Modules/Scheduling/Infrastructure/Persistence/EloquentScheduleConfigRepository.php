<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\Contracts\ScheduleConfigRepositoryInterface;
use App\Modules\Scheduling\Domain\ScheduleConfig;

/**
 * Eloquent implementation of {@see ScheduleConfigRepositoryInterface}.
 */
final class EloquentScheduleConfigRepository implements ScheduleConfigRepositoryInterface
{
    public function findById(string $id): ?ScheduleConfig
    {
        return ScheduleConfig::query()->find($id);
    }

    public function findForLocation(string $tenantId, string $companyId, string $locationId): ?ScheduleConfig
    {
        return ScheduleConfig::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->first();
    }

    public function save(ScheduleConfig $config): ScheduleConfig
    {
        $config->save();

        return $config;
    }
}
