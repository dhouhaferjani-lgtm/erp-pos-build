<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sweep\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;

/**
 * Scanner fixture (negative case). Tenant-scoped chains must NOT be flagged.
 */
class ScopedFindService
{
    public function fetch(int $id, int $tenantId): mixed
    {
        return Document::query()
            ->where('tenant_id', $tenantId)
            ->find($id);
    }

    public function fetchOrFail(int $id, int $companyId): mixed
    {
        return Document::query()
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }
}
