<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('inventory_batch_stock', function (Blueprint $table) {
            $table->id();

            // Multi-tenancy
            $table->uuid('tenant_id');

            // References
            $table->foreignId('batch_id')->constrained('product_batches')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained()->cascadeOnDelete();

            // Stock quantities
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('reserved_quantity', 15, 4)->default(0);

            // Computed available quantity (generated column)
            $table->decimal('available_quantity', 15, 4)
                ->storedAs('quantity - reserved_quantity')
                ->comment('Auto-computed: quantity - reserved_quantity');

            $table->timestamps();

            // Constraints
            $table->unique(['batch_id', 'location_id'], 'unique_batch_per_location');

            // Indexes
            $table->index('location_id', 'idx_batch_stock_location');
            $table->index('available_quantity', 'idx_batch_stock_available')
                ->where('available_quantity', '>', 0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batch_stock');
    }
};
