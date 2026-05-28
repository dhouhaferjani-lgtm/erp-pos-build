<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->foreignUuid('category_id')->constrained('unit_categories')->cascadeOnDelete();

            $table->string('code', 20)->index();
            $table->string('name', 100);
            $table->string('symbol', 10);

            // Conversion to base unit
            // How many base units = 1 of this unit
            // Example: 1 kg = 1000 g, so conversion_factor = 1000
            $table->decimal('conversion_factor', 20, 10)->default(1);

            // Display settings
            $table->integer('decimal_places')->default(2);
            $table->string('rounding_method', 20)->default('half_up');

            $table->boolean('is_base_unit')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['category_id', 'is_active']);
            $table->index(['tenant_id', 'is_active']);
        });

        // Now we can add the foreign key to unit_categories
        Schema::table('unit_categories', function (Blueprint $table) {
            $table->foreign('base_unit_id')->references('id')->on('units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unit_categories', function (Blueprint $table) {
            $table->dropForeign(['base_unit_id']);
        });

        Schema::dropIfExists('units');
    }
};
