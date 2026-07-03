<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Commands;

use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use App\Services\VerticalConfigService;
use Illuminate\Console\Command;

/**
 * Reconcile effective tenant modules after vertical default/extras changes.
 *
 * Default modules are config-driven, not copied onto tenant rows. Existing
 * tenants therefore need cache invalidation, and enabled_extras may need
 * pruning when a module is no longer compatible with the tenant vertical.
 *
 * @cross-tenant-by-design Iterates central tenant directory rows and updates
 * only central tenant metadata / global config cache entries.
 */
final class ReconcileModulesCommand extends Command
{
    protected $signature = 'tenant:reconcile-modules {--tenant= : Limit reconciliation to a single tenant id}';

    protected $description = 'Refresh tenant module caches and prune incompatible enabled extras.';

    public function handle(
        CompanyConfigService $companyConfigService,
        VerticalConfigService $verticalConfigService,
    ): int {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, string $tenantId) => $query->whereKey($tenantId))
            ->get();

        $refreshed = 0;
        $prunedExtras = 0;

        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            $compatibleExtras = $verticalConfigService->getCompatibleExtras($tenant->vertical);
            $defaultModules = $verticalConfigService->getDefaultModules($tenant->vertical);
            $allowedExtras = array_values(array_diff($compatibleExtras, $defaultModules));
            $enabledExtras = $this->enabledExtras($tenant);
            $reconciledExtras = array_values(array_intersect($enabledExtras, $allowedExtras));

            if ($reconciledExtras !== $enabledExtras) {
                $prunedExtras += count($enabledExtras) - count($reconciledExtras);
                $tenant->forceFill(['enabled_extras' => $reconciledExtras])->save();
            }

            $companyConfigService->invalidateForTenant($tenant->id);
            $refreshed++;
        }

        $this->info("Reconciled tenant modules: {$refreshed} refreshed, {$prunedExtras} pruned extras across {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function enabledExtras(Tenant $tenant): array
    {
        $extras = $tenant->enabled_extras;

        if (is_array($extras)) {
            return array_values($extras);
        }

        return [];
    }
}
