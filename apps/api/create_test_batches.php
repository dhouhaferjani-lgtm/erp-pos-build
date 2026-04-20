<?php

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Get tenant and company
$tenant = Tenant::where('slug', 'pharmabio-france')->first();
$company = Company::where('tenant_id', $tenant->id)->first();
$location = Location::where('company_id', $company->id)->where('is_default', true)->first();

// Get 10 products with batch tracking
$products = Product::where('company_id', $company->id)
    ->where('requires_batch_tracking', true)
    ->inRandomOrder()
    ->limit(10)
    ->get();

echo "Creating sample batches for testing...\n";
echo "Company: {$company->name}\n";
echo "Location: {$location->name}\n\n";

$batchesCreated = 0;

foreach ($products as $product) {
    // Create 2-3 batches per product
    $batchCount = rand(2, 3);

    for ($i = 0; $i < $batchCount; $i++) {
        $expiryDate = now()->addMonths(rand(3, 24));
        $manufacturingDate = now()->subMonths(rand(1, 6));

        // Use DB insert to avoid potential model issues
        // Insert batch and get the auto-generated ID
        $batchId = DB::table('product_batches')->insertGetId([
            'uuid' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'batch_number' => 'LOT-'.strtoupper(substr(md5(uniqid()), 0, 8)),
            'manufacturing_date' => $manufacturingDate->format('Y-m-d'),
            'expiry_date' => $expiryDate->format('Y-m-d'),
            'is_active' => true,
            'is_recalled' => false,
            'is_expired' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create stock for this batch
        $quantity = rand(50, 200);
        DB::table('inventory_batch_stock')->insert([
            'tenant_id' => $tenant->id,
            'batch_id' => $batchId,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batchesCreated++;
    }

    echo "✓ Created {$batchCount} batches for: {$product->name}\n";
}

$totalBatches = DB::table('product_batches')
    ->where('company_id', $company->id)
    ->count();

echo "\n✅ Done! Created {$batchesCreated} batches\n";
echo "Total batches in database: {$totalBatches}\n";
