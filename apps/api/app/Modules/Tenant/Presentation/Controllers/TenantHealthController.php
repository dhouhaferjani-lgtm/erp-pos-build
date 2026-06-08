<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Presentation\Controllers;

use App\Modules\Tenant\Application\Services\TenantHealthService;
use App\Modules\Tenant\Application\Services\TenantHealthSnapshot;
use Illuminate\Http\JsonResponse;

/**
 * T6 Phase 0b — read-only infra-health view of every tenant's per-tenant
 * database, gated behind super-admin auth in routes/api.php under the
 * existing /admin/monitoring/* group.
 *
 * Returns a single JSON array of snapshots. Cron scrapers can poll this
 * endpoint to alert on stale backups or unreachable tenant databases.
 */
final class TenantHealthController
{
    public function __construct(
        private readonly TenantHealthService $service,
    ) {}

    public function index(): JsonResponse
    {
        $snapshots = $this->service->snapshot();

        return response()->json([
            'data' => $snapshots->map(
                fn (TenantHealthSnapshot $s): array => $s->toArray()
            )->all(),
        ]);
    }
}
