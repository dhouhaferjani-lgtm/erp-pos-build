<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('inventory_batch_movements', function (Blueprint $table) {
            $table->id();

            // Multi-tenancy
            $table->uuid('tenant_id');

            // References
            $table->foreignId('batch_id')->constrained('product_batches')->cascadeOnDelete();
            $table->foreignUuid('movement_id')->constrained('stock_movements')->cascadeOnDelete();

            // Quantity (positive or negative)
            $table->decimal('quantity', 15, 4);

            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('batch_id', 'idx_batch_movements_batch');
            $table->index('movement_id', 'idx_batch_movements_movement');
            $table->index('created_at', 'idx_batch_movements_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batch_movements');
    }
};
