<?php

declare(strict_types=1);

namespace App\Modules\Partner\Infrastructure\Persistence;

use App\Modules\Partner\Application\Contracts\PartnerRepositoryInterface;
use App\Modules\Partner\Domain\Partner;

final class EloquentPartnerRepository implements PartnerRepositoryInterface
{
    public function findById(string $id): ?Partner
    {
        return Partner::query()->find($id);
    }

    public function findByIdForTenant(string $id, string $tenantId): ?Partner
    {
        return Partner::query()
            ->where('tenant_id', $tenantId)
            ->find($id);
    }
}
