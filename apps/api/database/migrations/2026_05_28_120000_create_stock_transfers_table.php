<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T1 — Stock Transfer module (intracompany scope).
 *
 * Aligns with docs/superpowers/specs/2026-05-24-t1-stock-transfer.md (Phase 2):
 * - stock_transfers + stock_transfer_lines
 * - Status lifecycle: draft -> in_transit -> completed | cancelled
 * - Transfer cost capitalization into company-wide WAC via the WeightedAverageCostService
 *   recordCostAdjustment seam (no per-location cost; quantity unchanged)
 *
 * Out of scope (deferred to follow-on tracks):
 * - Scenario B (intercompany auto sales/purchase pair)
 * - Per-location tax_id / branch_code
 * - InTransitAvailability per-company setting
 * - Batch preservation via inventory_batch_movements
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('transfer_number', 64);

            $table->string('transfer_type', 20)->default('intracompany');
            $table->string('status', 20)->default('draft');

            $table->foreignUuid('source_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUuid('destination_location_id')->constrained('locations')->restrictOnDelete();

            $table->text('notes')->nullable();

            $table->decimal('transfer_cost', 15, 4)->default(0);
            $table->string('transfer_cost_label', 64)->nullable();
            $table->string('transfer_cost_distribution', 20)->default('pro_rata_value');

            $table->string('idempotency_key', 128)->nullable();

            $table->foreignUuid('initiated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('initiated_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'transfer_number'], 'stock_transfers_company_number_unique');
            $table->unique(['tenant_id', 'company_id', 'idempotency_key'], 'stock_transfers_idempotency_unique');
            $table->index(['tenant_id', 'company_id', 'status'], 'stock_transfers_company_status_idx');
            $table->index(['tenant_id', 'company_id', 'created_at'], 'stock_transfers_company_created_idx');
            $table->index('source_location_id', 'stock_transfers_source_idx');
            $table->index('destination_location_id', 'stock_transfers_dest_idx');
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();

            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost_snapshot', 15, 4)->nullable();
            $table->decimal('allocated_transfer_cost', 15, 4)->default(0);

            $table->timestampsTz();

            $table->unique(['transfer_id', 'product_id'], 'stock_transfer_lines_transfer_product_unique');
            $table->index('product_id', 'stock_transfer_lines_product_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_distinct_locations CHECK (source_location_id <> destination_location_id)');
            DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_cost_nonneg CHECK (transfer_cost >= 0)');
            DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_quantity_positive CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
