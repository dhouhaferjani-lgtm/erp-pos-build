<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A2 — Location zones (shelf/section labels) + product-to-zone assignments.
 *
 * Zones are labels for count scoping and product placement ONLY — stock
 * quantity stays at (product, location[, variant]) grain (see
 * docs/superpowers/specs/2026-07-06-live-inventory-counting-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_zones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // tenant_id is a plain indexed uuid (NOT an FK): the `tenants` table
            // lives in the CENTRAL database, so under db-per-tenant a cross-DB FK
            // is impossible. Matches every sibling tenant table (companies,
            // products, stock_levels, ...). Covered by the tenant_id-leading
            // composite index below.
            $table->uuid('tenant_id');
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();

            $table->string('name', 255);
            $table->string('code', 50);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->unique(['location_id', 'code'], 'location_zones_location_code_unique');
            $table->index(['tenant_id', 'location_id'], 'location_zones_tenant_location_idx');
        });

        Schema::create('product_zone_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // tenant_id is a plain indexed uuid (NOT an FK) — see note above.
            $table->uuid('tenant_id');
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignUuid('zone_id')->constrained('location_zones')->cascadeOnDelete();

            $table->timestampsTz();

            $table->unique(['product_id', 'location_id'], 'product_zone_assignments_product_location_unique');
            $table->index('zone_id', 'product_zone_assignments_zone_idx');
            $table->index(['tenant_id', 'location_id'], 'product_zone_assignments_tenant_location_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_zone_assignments');
        Schema::dropIfExists('location_zones');
    }
};
