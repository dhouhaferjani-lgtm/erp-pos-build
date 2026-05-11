<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\HeldOrderService;
use App\Modules\Tenant\Domain\Tenant;

/**
 * Artisan command to expire held orders that have passed their expiry time.
 *
 * Scheduled every 15 minutes (see HeldOrderServiceProvider). Pre-round-5 the
 * command issued a single fleet-wide UPDATE against pos_held_orders with no
 * tenant_id / company_id predicate — 96 cross-tenant invocations per day in
 * production.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter). Per master plan §14 invariant 2,
 * schedulers MUST iterate explicitly per tenant; they MUST NOT issue cross-
 * tenant queries from the command body. Iteration: every Tenant x every
 * Company under that tenant; the underlying UPDATE in
 * {@see HeldOrderService::expireOrders()} carries BOTH predicates.
 */
final class ExpireHeldOrdersCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'pos:expire-held-orders';

    /** @var string */
    protected $description = 'Expire held POS orders that have passed their expiry time';

    public function __construct(
        CompanyContext $companyContext,
        private readonly HeldOrderService $heldOrderService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $totalExpired = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (&$totalExpired): int {
            foreach (Company::where('tenant_id', $tenant->id)->get() as $company) {
                /** @var Company $company */
                $totalExpired += $this->heldOrderService->expireOrders($tenant->id, $company->id);
            }

            return self::SUCCESS;
        });

        if ($totalExpired > 0) {
            $this->info("Expired {$totalExpired} held order(s).");
        } else {
            $this->info('No held orders to expire.');
        }

        return $exit;
    }
}
