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
        Schema::create('workshop_service_bundle_components', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('bundle_id')->constrained('workshop_service_bundles')->cascadeOnDelete();

            $table->string('component_type', 16);

            $table->foreignUuid('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->foreignUuid('nested_bundle_id')->nullable()->constrained('workshop_service_bundles')->restrictOnDelete();

            $table->decimal('quantity', 10, 3);
            $table->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('override_unit_price', 12, 3)->nullable();
            $table->boolean('is_optional')->default(false);
            $table->integer('display_order')->default(0);
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->index(['bundle_id', 'display_order'], 'idx_wsbc_bundle');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_wsbc_product ON workshop_service_bundle_components(product_id) WHERE product_id IS NOT NULL');
            DB::statement('CREATE INDEX idx_wsbc_service ON workshop_service_bundle_components(service_id) WHERE service_id IS NOT NULL');
            DB::statement('CREATE INDEX idx_wsbc_nested ON workshop_service_bundle_components(nested_bundle_id) WHERE nested_bundle_id IS NOT NULL');

            DB::statement("ALTER TABLE workshop_service_bundle_components ADD CONSTRAINT chk_wsbc_component_type CHECK (component_type IN ('part','labor','nested_bundle'))");
            DB::statement('ALTER TABLE workshop_service_bundle_components ADD CONSTRAINT chk_wsbc_not_self_ref CHECK (nested_bundle_id IS NULL OR nested_bundle_id <> bundle_id)');
            DB::statement('ALTER TABLE workshop_service_bundle_components ADD CONSTRAINT chk_wsbc_quantity_positive CHECK (quantity > 0)');
            DB::statement("ALTER TABLE workshop_service_bundle_components ADD CONSTRAINT chk_wsbc_exactly_one_component CHECK (
                (component_type = 'part'          AND product_id IS NOT NULL AND service_id IS NULL AND nested_bundle_id IS NULL) OR
                (component_type = 'labor'         AND product_id IS NULL AND service_id IS NOT NULL AND nested_bundle_id IS NULL) OR
                (component_type = 'nested_bundle' AND product_id IS NULL AND service_id IS NULL AND nested_bundle_id IS NOT NULL)
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_service_bundle_components');
    }
};
