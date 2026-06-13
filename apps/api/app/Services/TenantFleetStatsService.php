<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Facades\GlobalCache;
use Throwable;

/**
 * Fleet-wide aggregates for the super-admin dashboard.
 *
 * users/companies live in PER-TENANT databases (T6 Phase 0b), so totals are
 * computed by running the count inside each tenant's DB via $tenant->run().
 * The tenant_id where-clauses are kept: harmless in prod (per-tenant DB rows
 * still carry tenant_id) and REQUIRED for correctness in the shared-schema
 * test environment where run() does not swap databases.
 *
 * CACHE TOPOLOGY — GlobalCache, NOT the Cache facade: this is read from
 * central (admin) context and must not land in a tenant-tagged keyspace
 * (see VerticalConfigService for the canonical pattern). Unreachable tenant
 * DBs are skipped (logged) so one broken tenant cannot 500 the dashboard.
 */
class TenantFleetStatsService
{
    public const CACHE_KEY = 'admin:fleet-stats';

    private const CACHE_TTL_SECONDS = 300;

    /**
     * @return array{total_users: int, total_companies: int}
     */
    public function getUserAndCompanyTotals(): array
    {
        /** @var array{total_users: int, total_companies: int} */
        return GlobalCache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            function (): array {
                $totals = ['total_users' => 0, 'total_companies' => 0];

                foreach (Tenant::query()->cursor() as $tenant) {
                    try {
                        /** @var array{users: int, companies: int} $counts */
                        $counts = $tenant->run(static fn (): array => [
                            'users' => DB::table('users')->where('tenant_id', $tenant->id)->count(),
                            'companies' => DB::table('companies')->where('tenant_id', $tenant->id)->count(),
                        ]);
                    } catch (Throwable $e) {
                        Log::warning('Fleet stats: tenant database unreachable, skipping', [
                            'tenant_id' => $tenant->id,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    $totals['total_users'] += $counts['users'];
                    $totals['total_companies'] += $counts['companies'];
                }

                return $totals;
            }
        );
    }
}
