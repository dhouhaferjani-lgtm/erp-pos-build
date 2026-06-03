<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Per-product cost serialization seam.
 *
 * Acquires a PostgreSQL TRANSACTION-scoped advisory lock keyed on
 * (tenant_id, company_id, product_id) for every product in $productIds, in
 * ASCENDING product_id order (the consistent lock-ordering deadlock defense).
 * The lock auto-releases when the surrounding transaction ends (commit, rollback,
 * or crash) — no leak path.
 *
 * Acquired ONLY by operations that recompute cost_price OR create a stock_level
 * row (see plan "Coverage decision"). Pure decrements (sales/issues/POS) do NOT
 * acquire it: they serialize against an in-flight recompute via the stock_level
 * row locks the recompute holds, and cannot create the phantom rows row-locking
 * alone would miss.
 *
 * MUST be called INSIDE an open DB transaction. No-op on non-pgsql drivers
 * (SQLite test runner) — the real two-process race is exercised against
 * PostgreSQL CI only. hashtext collisions only over-serialize unrelated products
 * (safe); they never drop a needed lock, provided all callers build the SAME
 * pre-hash string for the same logical product.
 */
final class ProductCostLock
{
    /**
     * @template T
     *
     * @param  list<string>  $productIds
     * @param  Closure():T  $callback
     * @return T
     */
    public function acquire(string $tenantId, string $companyId, array $productIds, Closure $callback): mixed
    {
        if (DB::getDriverName() === 'pgsql') {
            $sorted = array_values(array_unique($productIds));
            sort($sorted, SORT_STRING);

            foreach ($sorted as $productId) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["wac:{$tenantId}:{$companyId}:{$productId}"]);
            }
        }

        return $callback();
    }
}
