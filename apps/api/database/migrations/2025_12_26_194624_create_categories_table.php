<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();

            // Materialized path for efficient tree queries
            // e.g., "1/5/12" means: Root(1) > Child(5) > Grandchild(12)
            $table->string('path')->default('')->index();
            $table->unsignedInteger('depth')->default(0);

            // For ordering within same parent
            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Unique slug within company
            $table->unique(['company_id', 'slug']);

            // Index for tree queries
            $table->index(['company_id', 'parent_id', 'sort_order']);
            $table->index(['company_id', 'path']);
        });

        // Add category_id to products
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')
                  ->nullable()
                  ->after('company_id')
                  ->constrained('categories')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('categories');
    }
};
