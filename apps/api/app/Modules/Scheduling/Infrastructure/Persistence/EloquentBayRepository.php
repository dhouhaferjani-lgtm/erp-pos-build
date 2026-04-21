<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Contracts\BayRepositoryInterface;

/**
 * Eloquent implementation of {@see BayRepositoryInterface}.
 */
final class EloquentBayRepository implements BayRepositoryInterface
{
    public function findById(string $id): ?Bay
    {
        return Bay::query()->find($id);
    }

    public function findForUpdate(string $id): ?Bay
    {
        return Bay::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function save(Bay $bay): Bay
    {
        $bay->save();

        return $bay;
    }

    /**
     * @return list<Bay>
     */
    public function listActiveForLocation(string $tenantId, string $companyId, string $locationId): array
    {
        /** @var list<Bay> $bays */
        $bays = Bay::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get()
            ->values()
            ->all();

        return $bays;
    }

    /**
     * @return list<Bay>
     */
    public function findByCompany(string $companyId): array
    {
        /** @var list<Bay> $bays */
        $bays = Bay::query()
            ->where('company_id', $companyId)
            ->orderBy('display_order')
            ->get()
            ->values()
            ->all();

        return $bays;
    }

    /**
     * @return list<Bay>
     */
    public function listActiveForCompany(string $companyId): array
    {
        /** @var list<Bay> $bays */
        $bays = Bay::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get()
            ->values()
            ->all();

        return $bays;
    }
}
