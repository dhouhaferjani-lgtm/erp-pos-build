<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies');
            $table->string('name');
            $table->string('code', 50);
            $table->string('type'); // CouponType enum
            $table->string('status')->default('active'); // CouponStatus enum
            $table->boolean('is_single_use')->default(false);
            $table->integer('max_uses')->nullable();
            $table->integer('use_count')->default(0);
            $table->integer('max_uses_per_customer')->nullable();
            $table->string('discount_type'); // percentage or fixed
            $table->decimal('discount_value', 12, 4);
            $table->decimal('max_discount_amount', 15, 2)->nullable();
            $table->decimal('minimum_order_amount', 15, 2)->nullable();
            $table->jsonb('qualifying_product_ids')->nullable();
            $table->jsonb('qualifying_category_ids')->nullable();
            $table->boolean('is_exclusive')->default(false);
            $table->string('stacking_group')->default('coupons');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['company_id', 'code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
