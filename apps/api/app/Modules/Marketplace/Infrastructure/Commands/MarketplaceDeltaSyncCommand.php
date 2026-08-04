<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Marketplace\Infrastructure\Jobs\SyncSellerListingsJob;
use App\Modules\Tenant\Domain\Tenant;

/**
 * Fan out a delta listing sync job per active marketplace seller.
 *
 * Scheduled every `marketplace.sync.delta_interval_minutes` minutes (see
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
 * before {@see SyncSellerListingsJob} resolves its seller.
 *
 * **Legacy row-level mode** (`tenancy_resolver.db_per_tenant=false` — the test
 * suite and pre-flip compat): `forEachTenant()` runs the closure once per
 * tenant WITHOUT switching databases, so the same seller set is fanned out N
 * times. The seller query is deliberately NOT filtered by `tenant_id`:
 * `marketplace_sellers.tenant_id` is nullable (external / Synerivia-owned
 * sellers carry NULL — see MarketplaceSellerFactory::external()), so adding the
 * predicate would silently drop those sellers under database-per-tenant, which
 * is the mode that actually ships. The redundant fan-out is a re-sync, not a
 * correctness bug.
 */
final class MarketplaceDeltaSyncCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'marketplace:delta-sync';

    /** @var string */
    protected $description = 'Dispatch a delta listing-sync job for every active marketplace seller, per tenant';

    public function __construct(
        CompanyContext $companyContext,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $dispatched = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (&$dispatched): int {
            // Short-circuit: most tenants have no marketplace seller at all,
            // and opening the fan-out cursor for them is pure overhead.
            if (! MarketplaceSeller::active()->exists()) {
                return self::SUCCESS;
            }

            MarketplaceSeller::active()->each(function (MarketplaceSeller $seller) use (&$dispatched): void {
                SyncSellerListingsJob::dispatch($seller->id);
                $dispatched++;
            });

            return self::SUCCESS;
        });

        $this->info("Dispatched {$dispatched} marketplace delta-sync job(s).");

        return $exit;
    }
}
