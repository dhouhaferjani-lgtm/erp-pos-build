<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->uuid('receipt_id');
            $table->uuid('partner_id')->nullable();
            $table->decimal('discount_amount', 15, 2);
            $table->timestamp('used_at');
            $table->timestamps();

            $table->index(['coupon_id', 'used_at']);
            $table->index(['receipt_id']);
            $table->index(['partner_id', 'coupon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
    }
};
