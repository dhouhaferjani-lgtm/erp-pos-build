<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates `workshop_work_order_lines` — the line-item table for a
     * WorkOrder. Supports 8 line types with polymorphic refs (product_id,
     * service_id, service_bundle_id) enforced by CHECK constraints per
     * Spec §5.1.
     *
     * `from_bundle_id` uses RESTRICT on delete (per validator) so a bundle
     * cannot be hard-deleted while historical WO lines reference it — admin
     * must unlink the WOs first.
     */
    public function up(): void
    {
        Schema::create('workshop_work_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('work_order_id')->constrained('workshop_work_orders')->cascadeOnDelete();

            $table->string('line_type', 24);
            $table->integer('display_order')->default(0);

            // Polymorphic refs (exactly one populated depending on line_type)
            $table->uuid('product_id')->nullable();
            $table->uuid('service_id')->nullable();
            $table->foreignUuid('service_bundle_id')
                ->nullable()
                ->constrained('workshop_service_bundles')
                ->nullOnDelete();

            // Snapshots
            $table->string('display_name', 300);
            $table->string('sku_or_code', 64)->nullable();
            $table->text('description')->nullable();

            // Quantity + unit
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 16);

            // Pricing
            $table->decimal('unit_price', 14, 3);
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('line_total_excl_tax', 14, 3);
            $table->decimal('line_total_tax', 14, 3);
            $table->decimal('line_total_incl_tax', 14, 3);

            // Labor-specific
            $table->decimal('labor_hours_estimated', 6, 2)->nullable();
            $table->decimal('labor_hours_actual', 6, 2)->nullable();
            $table->foreignUuid('assigned_technician_profile_id')
                ->nullable()
                ->constrained('workshop_technician_profiles')
                ->nullOnDelete();

            // Part-specific
            $table->uuid('stock_reservation_id')->nullable();
            $table->boolean('is_customer_supplied')->default(false);

            // Core-charge-specific
            $table->foreignUuid('core_deposit_partner_id')
                ->nullable()
                ->constrained('partners')
                ->nullOnDelete();
            $table->string('core_deposit_status', 16)->nullable();
            // Self-referential FK added after table creation so PG can resolve
            // the unique-on-id constraint (set by ->primary()) first.
            $table->uuid('core_return_of_line_id')->nullable();

            // Bundle tracking — RESTRICT keeps historical lines valid
            $table->foreignUuid('from_bundle_id')
                ->nullable()
                ->references('id')
                ->on('workshop_service_bundles')
                ->restrictOnDelete();
            $table->boolean('is_bundle_informational')->default(false);

            // Completion
            $table->boolean('is_completed')->default(false);
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['work_order_id', 'display_order'], 'idx_wwol_work_order');
        });

        // Add the self-referential FK on core_return_of_line_id now that the
        // table (and its primary key on `id`) exists and is committed.
        Schema::table('workshop_work_order_lines', function (Blueprint $table): void {
            $table->foreign('core_return_of_line_id')
                ->references('id')
                ->on('workshop_work_order_lines')
                ->nullOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX idx_wwol_product ON workshop_work_order_lines (product_id) '.
                'WHERE product_id IS NOT NULL'
            );
            DB::statement(
                'CREATE INDEX idx_wwol_bundle ON workshop_work_order_lines (from_bundle_id) '.
                'WHERE from_bundle_id IS NOT NULL'
            );
            DB::statement(
                'ALTER TABLE workshop_work_order_lines ADD CONSTRAINT chk_wwol_line_type CHECK '.
                "(line_type IN ('part','labor','core_charge','core_return','sublet','environmental_fee','misc_fee','bundle_header'))"
            );
            DB::statement(
                'ALTER TABLE workshop_work_order_lines ADD CONSTRAINT chk_wwol_quantity_nonneg CHECK (quantity >= 0)'
            );
            DB::statement(
                'ALTER TABLE workshop_work_order_lines ADD CONSTRAINT chk_wwol_core_deposit_status CHECK '.
                "(core_deposit_status IS NULL OR core_deposit_status IN ('outstanding','returned','expired','credited'))"
            );
            // Polymorphic-ref population rules
            DB::statement(
                'ALTER TABLE workshop_work_order_lines ADD CONSTRAINT chk_wwol_polymorphic_ref CHECK ('.
                "(line_type = 'part' AND product_id IS NOT NULL) OR ".
                "(line_type = 'labor' AND service_id IS NOT NULL) OR ".
                "(line_type = 'core_charge' AND product_id IS NOT NULL) OR ".
                "(line_type = 'core_return' AND product_id IS NOT NULL) OR ".
                "(line_type = 'sublet') OR ".
                "(line_type IN ('environmental_fee','misc_fee')) OR ".
                "(line_type = 'bundle_header' AND service_bundle_id IS NOT NULL)".
                ')'
            );
        } else {
            Schema::table('workshop_work_order_lines', function (Blueprint $table): void {
                $table->index('product_id', 'idx_wwol_product');
                $table->index('from_bundle_id', 'idx_wwol_bundle');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_work_order_lines');
    }
};
