<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('name')->nullable();
            $table->uuid('vehicle_id')->nullable();
            $table->string('status', 30)->default('active');
            $table->boolean('is_shared')->default(false);
            $table->jsonb('shared_with_user_ids')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
            $table->index(['company_id', 'user_id', 'status'], 'cc_company_user_status_idx');
        });

        Schema::create('catalog_cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained('catalog_carts')->cascadeOnDelete();
            $table->uuid('product_id')->nullable();
            $table->uuid('platform_article_id')->nullable();
            $table->string('article_number')->nullable();
            $table->string('article_name');
            $table->string('supplier_brand')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 15, 3)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('source', 20);
            $table->uuid('marketplace_listing_id')->nullable();
            $table->uuid('preferred_supplier_partner_id')->nullable();
            $table->uuid('reservation_id')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('marketplace_listing_id')->references('id')->on('marketplace_listings')->nullOnDelete();
            $table->foreign('preferred_supplier_partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('reservation_id')->references('id')->on('stock_reservations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_cart_items');
        Schema::dropIfExists('catalog_carts');
    }
};
