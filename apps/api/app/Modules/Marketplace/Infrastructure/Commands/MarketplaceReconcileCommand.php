<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Marketplace\Infrastructure\Jobs\ReconcileListingsJob;
use App\Modules\Tenant\Domain\Tenant;

/**
 * Fan out a full listing reconciliation job per active marketplace seller.
 *
 * Scheduled daily at `marketplace.sync.reconciliation_hour` (see
 * MarketplaceServiceProvider). Replaces the `$schedule->call(closure)`
 * registration that ran `MarketplaceSeller::active()` directly inside the
 * scheduler's CENTRAL container: under database-per-tenant that query throws
 * before anything is dispatched, and because it happens in the scheduler
 * process rather than a worker it never even reaches `failed_jobs`.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter). Per master plan §14 invariant 2,
 * schedulers MUST iterate explicitly per tenant; they MUST NOT issue cross-
 * tenant queries from the command body. The seller query AND the dispatch both
 * run inside the tenant's context, so QueueTenancyBootstrapper stamps the
 * tenant onto each per-seller payload and the worker re-initializes tenancy
 * before {@see ReconcileListingsJob} resolves its seller.
 *
 * **Legacy row-level mode** (`tenancy_resolver.db_per_tenant=false` — the test
 * suite and pre-flip compat): `forEachTenant()` runs the closure once per
 * tenant WITHOUT switching databases, so the same seller set is fanned out N
 * times. The seller query is deliberately NOT filtered by `tenant_id`:
 * `marketplace_sellers.tenant_id` is nullable (external / Synerivia-owned
 * sellers carry NULL — see MarketplaceSellerFactory::external()), so adding the
 * predicate would silently drop those sellers under database-per-tenant, which
 * is the mode that actually ships. The redundant fan-out re-runs an idempotent
 * reconciliation, not a correctness bug.
 */
final class MarketplaceReconcileCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'marketplace:reconcile';

    /** @var string */
    protected $description = 'Dispatch a full listing reconciliation job for every active marketplace seller, per tenant';

    public function __construct(
        CompanyContext $companyContext,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $dispatched = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (&$dispatched): int {
            if (! MarketplaceSeller::active()->exists()) {
                return self::SUCCESS;
            }

            MarketplaceSeller::active()->each(function (MarketplaceSeller $seller) use (&$dispatched): void {
                ReconcileListingsJob::dispatch($seller->id);
                $dispatched++;
            });

            return self::SUCCESS;
        });

        $this->info("Dispatched {$dispatched} marketplace reconciliation job(s).");

        return $exit;
    }
}
