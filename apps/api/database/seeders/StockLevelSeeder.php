<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StockLevelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first tenant and company (demo tenant)
        $tenant = Tenant::first();
        $company = Company::first();

        if (! $tenant || ! $company) {
            $this->command->error('No tenant or company found. Please run DatabaseSeeder first.');

            return;
        }

        // Get or create default location
        $defaultLocation = Location::forCompany($company->id)->first();

        if (! $defaultLocation) {
            $this->command->warn('No location found for company. Creating default warehouse...');
            $defaultLocation = Location::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $company->id,
                'name' => 'Main Warehouse',
                'code' => 'MAIN-WAREHOUSE',
                'type' => 'warehouse',
                'is_default' => true,
                'is_active' => true,
                'pos_enabled' => false,
            ]);
        }

        // Get count of products
        $totalProducts = Product::forCompany($company->id)->count();

        if ($totalProducts === 0) {
            $this->command->error('No products found. Please run DatabaseSeeder first.');

            return;
        }

        $this->command->info("Creating stock levels for {$totalProducts} products...");

        // Chunk products to avoid memory issues with 1000+ items
        $batchSize = 100;
        $created = 0;

        Product::forCompany($company->id)
            ->chunk($batchSize, function ($products) use ($tenant, $company, $defaultLocation, &$created) {
                foreach ($products as $product) {
                    // Only create stock levels for physical goods (not services)
                    if (! $product->is_physical) {
                        continue;
                    }

                    $quantity = (string) fake()->numberBetween(10, 500);
                    $minQuantity = (string) fake()->numberBetween(5, 20);
                    $maxQuantity = (string) fake()->numberBetween(100, 300);

                    // Use updateOrCreate to make seeder idempotent
                    StockLevel::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'location_id' => $defaultLocation->id,
                        ],
                        [
                            'id' => Str::uuid()->toString(),
                            'tenant_id' => $tenant->id,
                            'company_id' => $company->id,
                            'quantity' => $quantity,
                            'reserved' => '0',
                            'min_quantity' => $minQuantity,
                            'max_quantity' => $maxQuantity,
                        ]
                    );

                    $created++;
                }
            });

        $this->command->info("Created stock levels for {$created} products in location: {$defaultLocation->name}");
    }
}
