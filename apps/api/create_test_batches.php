<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Get tenant and company
$tenant = \App\Modules\Tenant\Domain\Tenant::where('slug', 'pharmabio-france')->first();
$company = \App\Modules\Company\Domain\Company::where('tenant_id', $tenant->id)->first();
$location = \App\Modules\Company\Domain\Location::where('company_id', $company->id)->where('is_default', true)->first();

// Get 10 products with batch tracking
$products = \App\Modules\Product\Domain\Product::where('company_id', $company->id)
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
        $batchId = \Illuminate\Support\Facades\DB::table('product_batches')->insertGetId([
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
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
        \Illuminate\Support\Facades\DB::table('inventory_batch_stock')->insert([
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

$totalBatches = \Illuminate\Support\Facades\DB::table('product_batches')
    ->where('company_id', $company->id)
    ->count();

echo "\n✅ Done! Created {$batchesCreated} batches\n";
echo "Total batches in database: {$totalBatches}\n";
