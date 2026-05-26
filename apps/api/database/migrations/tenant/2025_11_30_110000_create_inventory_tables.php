<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Note: locations table is now created in 2025_11_30_215820_create_locations_table.php
        // as part of Phase 0 architecture refactor (locations are scoped to company, not tenant)

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('reserved', 15, 2)->default(0);
            $table->decimal('min_quantity', 15, 2)->nullable();
            $table->decimal('max_quantity', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id', 'location_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'location_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('movement_type');
            $table->decimal('quantity', 15, 2);
            $table->decimal('quantity_before', 15, 2);
            $table->decimal('quantity_after', 15, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'created_at']);
            $table->index(['tenant_id', 'location_id', 'created_at']);
            $table->index(['tenant_id', 'movement_type', 'created_at']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');
        // Note: locations table is now handled in 2025_11_30_215820_create_locations_table.php
    }
};
