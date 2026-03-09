<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('seller_id')->constrained('marketplace_sellers')->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->uuid('platform_article_id')->nullable();
            $table->string('article_number')->nullable();
            $table->string('barcode')->nullable();
            $table->string('product_name');
            $table->string('supplier_brand')->nullable();
            $table->string('quality_tier', 30)->nullable();
            $table->decimal('price', 15, 3);
            $table->string('currency', 3);
            $table->decimal('quantity_available', 10, 2)->default(0);
            $table->decimal('min_order_quantity', 10, 2)->default(1);
            $table->string('listing_status', 30)->default('active');
            $table->uuid('source_product_id')->nullable();
            $table->timestamp('price_updated_at')->nullable();
            $table->timestamp('stock_updated_at')->nullable();
            $table->timestamps();

            $table->index(['country_code', 'platform_article_id'], 'ml_country_platform_idx');
            $table->index(['country_code', 'article_number'], 'ml_country_article_idx');
            $table->index(['country_code', 'barcode'], 'ml_country_barcode_idx');
            $table->index(['seller_id', 'listing_status'], 'ml_seller_status_idx');
            $table->index(['country_code', 'listing_status', 'price'], 'ml_country_status_price_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_listings');
    }
};
