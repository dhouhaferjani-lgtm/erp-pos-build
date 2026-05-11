<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use Illuminate\Console\Command;

/**
 * @cross-tenant-by-design Maintenance task that left-joins products against stock_levels fleet-wide to find orphans; per-product remediation is anchored on the product's own company_id.
 */
class FixOrphanedProducts extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'import:fix-orphaned-products
                            {--dry-run : Show what would be done without making changes}
                            {--company= : Fix only for specific company UUID}';

    /**
     * The console command description.
     */
    protected $description = 'Create stock_level records for products without any stock';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $companyId = $this->option('company');

        $this->info('Finding products without stock levels...');

        // Find products without stock levels
        $query = Product::query()
            ->leftJoin('stock_levels', 'products.id', '=', 'stock_levels.product_id')
            ->whereNull('stock_levels.id');

        if ($companyId) {
            $query->where('products.company_id', $companyId);
        }

        $orphanedProducts = $query->select('products.*')->get();

        $this->info("Found {$orphanedProducts->count()} products without stock levels");

        if ($orphanedProducts->count() === 0) {
            $this->info('No orphaned products found. Nothing to do.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $created = 0;
        $errors = 0;
        $skipped = 0;

        $progressBar = $this->output->createProgressBar($orphanedProducts->count());
        $progressBar->start();

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
                $this->newLine();
                $this->error("Company {$product->company_id} has no locations. Skipping product {$product->sku}");
                $errors++;
                $progressBar->advance();

                continue;
            }

            if (! $isDryRun) {
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
                    $this->newLine();
                    $this->error("Failed to create stock level for product {$product->sku}: {$e->getMessage()}");
                    $errors++;
                }
            } else {
                $created++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total orphaned products', $orphanedProducts->count()],
                ['Stock levels created', $created],
                ['Errors', $errors],
                ['Skipped', $skipped],
            ]
        );

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - Run without --dry-run to apply changes');

            return self::SUCCESS;
        }

        $this->info('Done!');

        return self::SUCCESS;
    }
}
