<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_line_batch_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_transfer_line_id')
                ->constrained('stock_transfer_lines')
                ->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('product_batches')->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->timestampsTz();

            $table->unique(['stock_transfer_line_id', 'batch_id'], 'stock_transfer_line_batch_unique');
            $table->index(['tenant_id', 'company_id'], 'stock_transfer_batch_alloc_company_idx');
            $table->index('batch_id', 'stock_transfer_batch_alloc_batch_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_transfer_line_batch_allocations ADD CONSTRAINT stock_transfer_batch_alloc_quantity_positive CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_line_batch_allocations');
    }
};
