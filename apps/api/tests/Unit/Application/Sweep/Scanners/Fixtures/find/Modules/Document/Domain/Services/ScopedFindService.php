<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sweep\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;

/**
 * Scanner fixture (negative case). Tenant-scoped chains must NOT be flagged.
 */
class ScopedFindService
{
    public function fetch(int $id, int $tenantId, int $companyId): mixed
    {
        return Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->find($id);
    }

    public function fetchOrFail(int $id, int $tenantId, int $companyId): mixed
    {
        return Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }
}
