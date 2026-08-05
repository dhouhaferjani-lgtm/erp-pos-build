<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;

/**
 * Tenant-isolation: cat-(a-per-tenant-iter) behind an EXPLICIT scope,
 * converted 2026-08-05 (cat-(b) wave 2).
 *
 * The annotation claimed a fleet-wide left join of `products` against
 * `stock_levels`. Both are TENANT tables, so after the 2026-05-28
 * database-per-tenant flip the query raised 42P01 on the console's CENTRAL
 * connection and no orphan was ever repaired.
 *
 * A product with no `stock_levels` row is invisible to inventory, so a repair
 * pass that silently skips a tenant leaves that tenant broken with no signal.
 * The scope must be named: `--tenant=<uuid>` or `--all-tenants`. `--company`
 * remains an in-tenant narrowing filter.
 *
 * The remediation LOGIC — default-location resolution, then first active
 * location, then skip — is untouched.
 */
final class FixOrphanedProducts extends TenantScopedCommand
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'import:fix-orphaned-products
                            {--tenant= : Tenant UUID to repair (required unless --all-tenants)}
                            {--all-tenants : Deliberate fleet-wide run over every reachable tenant}
                            {--dry-run : Show what would be done without making changes}
                            {--company= : Fix only for specific company UUID}';

    /**
     * The console command description.
     */
    protected $description = 'Create stock_level records for products without any stock (per tenant)';

    public function __construct(CompanyContext $companyContext)
    {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $companyFilter = $this->stringOption('company');

        $this->info('Finding products without stock levels...');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $totalOrphaned = 0;
        $created = 0;
        $errors = 0;

        $exit = $this->forEachExplicitlySelectedTenant(
            $this->stringOption('tenant'),
            $this->option('all-tenants') === true,
            function (Tenant $tenant) use ($isDryRun, $companyFilter, &$totalOrphaned, &$created, &$errors): int {
                // The explicit tenant_id predicate is redundant under
                // database-per-tenant and load-bearing in single-schema
                // compatibility mode.
                $orphanedProducts = Product::query()
                    ->leftJoin('stock_levels', 'products.id', '=', 'stock_levels.product_id')
                    ->whereNull('stock_levels.id')
                    ->where('products.tenant_id', $tenant->id)
                    ->when($companyFilter !== null, fn ($query) => $query->where('products.company_id', $companyFilter))
                    ->select('products.*')
                    ->get();

                $this->info(sprintf(
                    'TENANT %s (%s): %d product(s) without stock levels.',
                    $tenant->id,
                    $tenant->slug,
                    $orphanedProducts->count(),
                ));

                if ($orphanedProducts->isEmpty()) {
                    return self::SUCCESS;
                }

                $totalOrphaned += $orphanedProducts->count();
                $tenantExit = self::SUCCESS;

                foreach ($orphanedProducts as $product) {
                    // Get default location for product's company
                    $defaultLocation = Location::where('company_id', $product->company_id)
                        ->where('is_default', true)
                        ->where('is_active', true)
                        ->first();

                    if (! $defaultLocation) {
                        // No default location - try first active location
                        $defaultLocation = Location::where('company_id', $product->company_id)
                            ->where('is_active', true)
                            ->orderBy('created_at')
                            ->first();
                    }

                    if (! $defaultLocation) {
                        $this->error("Company {$product->company_id} has no locations. Skipping product {$product->sku}");
                        $errors++;
                        // A skipped product stays invisible to inventory — the
                        // pre-conversion command reported it and still exited 0.
                        $tenantExit = self::FAILURE;

                        continue;
                    }

                    if ($isDryRun) {
                        $created++;

                        continue;
                    }

                    try {
                        StockLevel::create([
                            'tenant_id' => $product->tenant_id,
                            'company_id' => $product->company_id,
                            'product_id' => $product->id,
                            'location_id' => $defaultLocation->id,
                            'quantity' => 0,
                            'reserved' => 0,
                        ]);

                        $created++;
                    } catch (\Exception $e) {
                        $this->error("Failed to create stock level for product {$product->sku}: {$e->getMessage()}");
                        $errors++;
                        $tenantExit = self::FAILURE;
                    }
                }

                return $tenantExit;
            },
        );

        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total orphaned products', $totalOrphaned],
                ['Stock levels created', $created],
                ['Errors', $errors],
            ]
        );

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - Run without --dry-run to apply changes');
        }

        return $exit;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
