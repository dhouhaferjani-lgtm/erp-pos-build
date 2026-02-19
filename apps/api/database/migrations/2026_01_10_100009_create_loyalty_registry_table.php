<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_registry', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Entity type (e.g., "product", "menu_item", "service")
            $table->string('entity_type', 50)->unique();

            // Fully qualified class name
            $table->string('entity_class', 255);

            // Category type (optional)
            $table->string('category_type', 50)->nullable();
            $table->string('category_class', 255)->nullable();

            // Metadata
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('entity_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_registry');
    }
};
