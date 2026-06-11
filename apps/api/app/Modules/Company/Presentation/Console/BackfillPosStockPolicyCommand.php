<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Console;

use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * @cross-tenant-by-design Walks every tenant and applies the vertical-derived default
 * PosStockPolicy to companies that have not been explicitly overridden (or sets all
 * when run post-migration). Under db_per_tenant mode each tenant is initialized in
 * turn so the DB::table() write lands in the correct per-tenant database.
 */
class BackfillPosStockPolicyCommand extends Command
{
    protected $signature = 'pos:stock-policy-backfill
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill companies.pos_stock_policy based on the tenant vertical (spec §4.2).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $dbPerTenant = (bool) config('tenancy_resolver.db_per_tenant', false);

        /** @var list<Tenant> $tenants */
        $tenants = Tenant::query()->get()->all();

        if ($tenants === []) {
            $this->info('No tenants found. Nothing to backfill.');

            return self::SUCCESS;
        }

        $updated = 0;

        foreach ($tenants as $tenant) {
            $initialized = false;

            try {
                if ($dbPerTenant) {
                    tenancy()->initialize($tenant);
                    $initialized = true;
                }

                $target = PosStockPolicy::defaultForVertical($tenant->vertical);

                if ($target === PosStockPolicy::Off) {
                    if ($dryRun) {
                        $count = DB::table('companies')
                            ->where('tenant_id', $tenant->id)
                            ->count();
                        if ($count > 0) {
                            $this->line(sprintf(
                                '[DRY-RUN] tenant %s (%s): would set %d companies to %s',
                                $tenant->slug,
                                $tenant->vertical->value,
                                $count,
                                $target->value,
                            ));
                        }
                        $updated += $count;
                    } else {
                        $rows = DB::table('companies')
                            ->where('tenant_id', $tenant->id)
                            ->update(['pos_stock_policy' => $target->value]);
                        if ($rows > 0) {
                            $this->line(sprintf(
                                'tenant %s (%s): set %d companies to %s',
                                $tenant->slug,
                                $tenant->vertical->value,
                                $rows,
                                $target->value,
                            ));
                        }
                        $updated += $rows;
                    }
                }
            } finally {
                if ($initialized && tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        if ($dryRun) {
            $this->info(sprintf('[DRY-RUN] Would update %d company record(s). No changes written.', $updated));
        } else {
            $this->info(sprintf('Backfill complete: %d company record(s) updated.', $updated));
        }

        return self::SUCCESS;
    }
}
