<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composite_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('vertical_type', 50)->default('generic');
            $table->decimal('base_price', 15, 4)->default(0);
            $table->string('production_type', 50)->default('made_to_order');
            $table->string('tax_rate', 10)->nullable();
            // default_recipe_id FK added in migration 2 after recipes table exists
            $table->uuid('default_recipe_id')->nullable();
            $table->foreignUuid('stock_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->string('image_url', 500)->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'vertical_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composite_items');
    }
};
