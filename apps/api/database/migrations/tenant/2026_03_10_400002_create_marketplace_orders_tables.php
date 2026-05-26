<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('seller_id')->constrained('marketplace_sellers');
            $table->uuid('buyer_tenant_id');
            $table->uuid('buyer_company_id');
            $table->string('order_number', 50);
            $table->string('order_status', 30)->default('pending');
            $table->string('country_code', 2);
            $table->string('currency', 3);
            $table->decimal('subtotal', 15, 3)->default(0);
            $table->decimal('commission_amount', 15, 3)->default(0);
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->decimal('total', 15, 3)->default(0);
            $table->uuid('buyer_document_id')->nullable();
            $table->uuid('seller_document_id')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->foreign('buyer_company_id')->references('id')->on('companies');
            $table->foreign('buyer_document_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('seller_document_id')->references('id')->on('documents')->nullOnDelete();

            $table->index(['buyer_tenant_id', 'buyer_company_id', 'order_status'], 'mo_buyer_status_idx');
            $table->index(['seller_id', 'order_status'], 'mo_seller_status_idx');
        });

        Schema::create('marketplace_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained('marketplace_listings');
            $table->string('article_number')->nullable();
            $table->string('article_name');
            $table->string('supplier_brand')->nullable();
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 15, 3);
            $table->decimal('line_total', 15, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_lines');
        Schema::dropIfExists('marketplace_orders');
    }
};
