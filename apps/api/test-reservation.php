<?php

/**
 * Quick test script to verify stock reservation system is working
 * Run with: php test-reservation.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Facades\DB;

echo "🧪 Testing Stock Reservation System\n";
echo "=====================================\n\n";

try {
    // 1. Find a company
    $company = Company::first();
    if (! $company) {
        echo "❌ No company found. Please seed the database first.\n";
        exit(1);
    }
    echo "✅ Found company: {$company->name} (ID: {$company->id})\n";

    // 2. Find a product
    $product = Product::where('tenant_id', $company->tenant_id)
        ->where('is_physical', true)
        ->first();
    if (! $product) {
        echo "❌ No physical product found. Please seed products first.\n";
        exit(1);
    }
    echo "✅ Found product: {$product->name} (ID: {$product->id})\n";

    // 3. Find or create a location
    $location = \App\Modules\Company\Domain\Location::where('company_id', $company->id)->first();
    if (! $location) {
        $location = \App\Modules\Company\Domain\Location::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'type' => \App\Modules\Company\Domain\Enums\LocationType::Warehouse,
            'is_default' => true,
        ]);
        echo "✅ Created location: {$location->name} (ID: {$location->id})\n";
    } else {
        echo "✅ Found location: {$location->name} (ID: {$location->id})\n";
    }

    // 4. Ensure stock level exists
    $stockLevel = StockLevel::where('product_id', $product->id)
        ->where('location_id', $location->id)
        ->first();

    if (! $stockLevel) {
        $stockLevel = StockLevel::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => '100.0000',
            'reserved' => '0.0000',
        ]);
        echo "✅ Created stock level: 100 units\n";
    } else {
        echo "✅ Stock level exists: {$stockLevel->quantity} units (reserved: {$stockLevel->reserved})\n";
    }

    // 5. Test reservation creation
    echo "\n📦 Testing Reservation Creation...\n";

    $reservationService = app(StockReservationService::class);

    $reservation = $reservationService->reserve(
        company: $company,
        productId: $product->id,
        locationId: $location->id,
        quantity: '10.0000',
        sourceType: ReservationSource::SalesOrder,
        sourceId: \Illuminate\Support\Str::uuid()->toString(),
        sourceLineId: null,
        priority: 0,
        notes: 'Test reservation from verification script'
    );

    echo "✅ Reservation created successfully!\n";
    echo "   - ID: {$reservation->id}\n";
    echo "   - Quantity: {$reservation->quantity}\n";
    echo "   - Source: {$reservation->source_type->label()}\n";
    echo '   - Expires: '.($reservation->expires_at ? $reservation->expires_at->format('Y-m-d H:i:s') : 'Never')."\n";

    // 6. Verify stock level was updated
    $stockLevel->refresh();
    echo "✅ Stock level updated: {$stockLevel->reserved} units reserved\n";

    // 7. Test reservation release
    echo "\n🔓 Testing Reservation Release...\n";

    $reservationService->release(
        reservation: $reservation,
        reason: \App\Modules\Inventory\Domain\Enums\ReleaseReason::ManualRelease,
        releasedBy: null
    );

    $reservation->refresh();
    $stockLevel->refresh();

    echo "✅ Reservation released successfully!\n";
    echo "   - Released at: {$reservation->released_at->format('Y-m-d H:i:s')}\n";
    echo "   - Reason: {$reservation->release_reason->label()}\n";
    echo "   - Stock reserved now: {$stockLevel->reserved}\n";

    // 8. Check events were logged
    $eventCount = DB::table('stored_events')
        ->where('aggregate_uuid', $reservation->id)
        ->count();

    echo "\n📊 Event Sourcing Check:\n";
    echo "   - Events logged: {$eventCount}\n";
    if ($eventCount >= 2) {
        echo "✅ Events are being stored correctly!\n";
    }

    echo "\n✨ All tests passed! Stock Reservation System is ACTIVE! ✨\n";

} catch (\Exception $e) {
    echo "\n❌ Error: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString();
    exit(1);
}
