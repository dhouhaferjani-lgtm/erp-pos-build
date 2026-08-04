<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Tenant\Domain\Tenant;

/**
 * Expire stock reservations that have passed their expiry time.
 *
 * Scheduled every 15 minutes (see routes/console.php). Replaces the deleted
 * `ExpireReservationsJob` queue job, which ran the sweep from CENTRAL context:
 * under database-per-tenant (staging/prod since 2026-06-19) the central
 * database has no `stock_reservations` table, so every tick failed with
 * `SQLSTATE[42P01]` — 4,211 central `failed_jobs` rows accumulated between
 * 2026-07-03 and 2026-08-04.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter). Per master plan §14 invariant 2,
 * schedulers MUST iterate explicitly per tenant; they MUST NOT issue cross-
 * tenant queries from the command body. Each pass runs
 * {@see StockReservationService::expireReservations()} inside the tenant's own
 * database connection (opened by {@see self::forEachTenant()}).
 *
 * **Legacy row-level mode** (`tenancy_resolver.db_per_tenant=false` — the test
 * suite and pre-flip compat): `forEachTenant()` runs the closure once per
 * tenant WITHOUT switching databases, so the sweep executes N times against
 * the single shared database. `stock_reservations` carries no `tenant_id`
 * column, so the sweep cannot be tenant-scoped in that mode. It is safe:
 * `StockReservation::expired()` filters on `released_at IS NULL`, so passes
 * 2..N find nothing and no quantity is decremented twice — correct but
 * redundant.
 */
final class ExpireStockReservationsCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'inventory:expire-reservations';

    /** @var string */
    protected $description = 'Expire stock reservations that have passed their expiry time';

    public function __construct(
        CompanyContext $companyContext,
        private readonly StockReservationService $reservationService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $totalExpired = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (&$totalExpired): int {
            $totalExpired += $this->reservationService->expireReservations();

            return self::SUCCESS;
        });

        if ($totalExpired > 0) {
            $this->info("Expired {$totalExpired} reservation(s).");
        } else {
            $this->info('No reservations to expire.');
        }

        return $exit;
    }
}
