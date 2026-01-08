<?php

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
        Schema::create('product_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('filename'); // Generated: {uuid}.{ext}
            $table->string('original_filename'); // User's original filename
            $table->string('storage_path'); // products/{tenant_id}/{product_id}/{filename}
            $table->string('storage_disk')->default('s3'); // 's3' for MinIO
            $table->string('mime_type');
            $table->unsignedInteger('file_size'); // bytes
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->string('thumbnail_path')->nullable(); // Future: auto-generated thumbnails
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');

            // Indexes for efficient queries
            $table->index(['tenant_id', 'product_id'], 'idx_product_images_tenant_product');
            $table->index(['product_id', 'sort_order'], 'idx_product_images_sort');

            // Unique constraint: only one primary image per product
            // Note: This is enforced at application level instead of DB constraint
            // because partial unique indexes with WHERE clause don't work well across all DB engines
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
