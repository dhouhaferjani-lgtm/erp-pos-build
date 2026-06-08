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
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('variant_code', 100);
            $table->string('sku', 100);
            $table->string('barcode', 100)->nullable();
            $table->string('name_suffix', 128);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->decimal('price_override', 15, 4)->nullable();
            $table->decimal('cost_override', 15, 4)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_id', 'variant_code']);
            $table->index(['tenant_id', 'product_id', 'is_active', 'display_order']);
            $table->index(['tenant_id', 'company_id', 'is_active']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX product_variants_tenant_sku_unique
                           ON product_variants (tenant_id, sku) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX product_variants_tenant_barcode_unique
                           ON product_variants (tenant_id, barcode) WHERE barcode IS NOT NULL AND deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX product_variants_default_unique
                           ON product_variants (product_id) WHERE is_default = true AND deleted_at IS NULL');
            DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_nonneg
                           CHECK (price_override IS NULL OR price_override >= 0)');
            DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_cost_nonneg
                           CHECK (cost_override IS NULL OR cost_override >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
