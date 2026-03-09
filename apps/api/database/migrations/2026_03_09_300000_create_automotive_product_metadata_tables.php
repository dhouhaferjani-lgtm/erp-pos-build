<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automotive_product_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id')->unique();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            // Platform link
            $table->uuid('platform_article_id')->nullable();
            $table->string('platform_link_status', 20)->default('unlinked');

            // Article identification
            $table->string('article_number', 100)->nullable();
            $table->string('supplier_brand', 200)->nullable();
            $table->string('product_group_name', 200)->nullable();
            $table->string('brand_quality_tier', 30)->nullable();
            $table->string('article_status', 20)->default('active');

            // Data quality
            $table->smallInteger('confidence_score')->default(0);
            $table->string('data_source', 50)->default('manual');

            // Physical attributes
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->jsonb('dimensions')->nullable();

            // Supersession
            $table->uuid('superseded_by_product_id')->nullable();
            $table->foreign('superseded_by_product_id')->references('id')->on('products')->nullOnDelete();

            // Flags
            $table->boolean('is_universal_fit')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('platform_synced_at')->nullable();

            // Tire-specific (TireShop)
            $table->smallInteger('tire_width')->nullable();
            $table->smallInteger('tire_aspect_ratio')->nullable();
            $table->smallInteger('tire_rim_diameter')->nullable();
            $table->string('tire_speed_rating', 5)->nullable();
            $table->smallInteger('tire_load_index')->nullable();
            $table->string('tire_season', 20)->nullable();

            // Glass-specific (CarGlass)
            $table->string('glass_type', 50)->nullable();
            $table->string('glass_tinting', 20)->nullable();

            $table->timestamps();

            // Indexes
            $table->index('article_number');
            $table->index('platform_link_status');
            $table->index('article_status');
            $table->index('supplier_brand');
            $table->index('brand_quality_tier');
            $table->index('superseded_by_product_id');
            $table->index(['tire_width', 'tire_aspect_ratio', 'tire_rim_diameter']);
        });

        Schema::create('automotive_product_cross_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('automotive_metadata_id');
            $table->foreign('automotive_metadata_id', 'apcr_metadata_fk')
                ->references('id')->on('automotive_product_metadata')->cascadeOnDelete();

            $table->string('reference_type', 20);
            $table->string('reference_number', 200);
            $table->string('manufacturer_name', 200)->nullable();
            $table->uuid('platform_cross_ref_id')->nullable();

            $table->timestamps();

            $table->index('reference_number');
            $table->index('reference_type');
            $table->index('automotive_metadata_id', 'apcr_metadata_idx');
        });

        Schema::create('automotive_product_vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('automotive_metadata_id');
            $table->foreign('automotive_metadata_id', 'apv_metadata_fk')
                ->references('id')->on('automotive_product_metadata')->cascadeOnDelete();

            $table->uuid('platform_vehicle_id')->nullable();
            $table->string('vehicle_type', 10);
            $table->string('vehicle_display', 500);
            $table->smallInteger('year_from')->nullable();
            $table->smallInteger('year_to')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('platform_synced_at')->nullable();

            $table->timestamps();

            $table->index('automotive_metadata_id', 'apv_metadata_idx');
            $table->index('platform_vehicle_id');
        });

        Schema::create('automotive_product_criteria', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('automotive_metadata_id');
            $table->foreign('automotive_metadata_id', 'apc_metadata_fk')
                ->references('id')->on('automotive_product_metadata')->cascadeOnDelete();

            $table->string('criteria_key', 100);
            $table->string('criteria_label', 200);
            $table->string('value', 500);
            $table->string('unit', 20)->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->uuid('platform_criteria_id')->nullable();

            $table->timestamps();

            $table->index('automotive_metadata_id', 'apc_metadata_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automotive_product_criteria');
        Schema::dropIfExists('automotive_product_vehicles');
        Schema::dropIfExists('automotive_product_cross_references');
        Schema::dropIfExists('automotive_product_metadata');
    }
};
