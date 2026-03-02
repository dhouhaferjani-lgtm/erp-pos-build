<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make payment_method_id nullable on payments table.
 *
 * On-account payments (customer pays later) may not specify a payment method.
 * MultiPaymentService sets payment_method_id to null for these cases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->uuid('payment_method_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->uuid('payment_method_id')->nullable(false)->change();
        });
    }
};
