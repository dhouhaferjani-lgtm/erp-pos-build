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
     *
     * @param  Company|null  $company  Optional specific company to seed for
     */
    public function run(?Company $company = null): void
    {
        // If a specific company is provided, seed only for that company
        if ($company !== null) {
            $this->seedForCompany($company);

            return;
        }

        // Otherwise, seed for ALL companies (dev mode)
        $companies = Company::all();

        if ($companies->isEmpty()) {
            $this->command?->error('No companies found. Please run DatabaseSeeder first.');

            return;
        }

        foreach ($companies as $comp) {
            $this->seedForCompany($comp);
        }
    }

    /**
     * Seed stock levels for a specific company.
     */
    private function seedForCompany(Company $company): void
    {
        $tenant = Tenant::find($company->tenant_id);

        if (! $tenant) {
            return;
        }

        // Get or create default location
        $defaultLocation = Location::forCompany($company->id)->where('is_default', true)->first();

        if (! $defaultLocation) {
            $this->command?->warn("No default location found for {$company->name}. Creating fallback warehouse...");
            $defaultLocation = Location::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $company->id,
                'name' => 'Main Warehouse',
                'code' => 'MAIN-WAREHOUSE',
                'type' => 'warehouse',
                'is_default' => true,
                'is_active' => true,
                'pos_enabled' => false,

                // Copy address from company (fallback only)
                'address_street' => $company->address_street,
                'address_city' => $company->address_city,
                'address_postal_code' => $company->address_postal_code,
                'address_country' => $company->country_code,
                'phone' => $company->phone,
                'email' => $company->email,
            ]);
        }

        // Get count of products
        $totalProducts = Product::forCompany($company->id)->count();

        if ($totalProducts === 0) {
            $this->command?->warn("No products found for {$company->name}. Skipping stock levels.");

            return;
        }

        $this->command?->info("Creating stock levels for {$totalProducts} products in {$company->name}...");

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

        $this->command?->info("Created stock levels for {$created} products in {$company->name} ({$defaultLocation->name})");
    }
}
