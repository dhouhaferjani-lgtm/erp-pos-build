<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('allocation_method', 30)->default('fifo');

            // Extensibility: Phase 2 - FX and discounts
            $table->decimal('exchange_rate_at_payment', 15, 6)->nullable();
            $table->decimal('fx_gain_loss_amount', 15, 4)->nullable();
            $table->decimal('discount_taken', 15, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'allocation_method',
                'exchange_rate_at_payment',
                'fx_gain_loss_amount',
                'discount_taken',
            ]);
        });
    }
};
