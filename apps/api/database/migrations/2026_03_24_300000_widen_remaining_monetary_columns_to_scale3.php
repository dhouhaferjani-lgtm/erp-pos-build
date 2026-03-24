<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('line_tax_amount', 15, 3)->nullable()->change();
        });

        Schema::table('loyalty_enrollments', function (Blueprint $table) {
            $table->decimal('current_balance', 15, 3)->default(0)->change();
            $table->decimal('lifetime_earned', 15, 3)->default(0)->change();
            $table->decimal('lifetime_redeemed', 15, 3)->default(0)->change();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->decimal('fee_fixed', 10, 3)->default(0)->change();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_monthly', 10, 3)->nullable()->change();
            $table->decimal('price_yearly', 10, 3)->nullable()->change();
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->decimal('price', 10, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('line_tax_amount', 15, 2)->nullable()->change();
        });

        Schema::table('loyalty_enrollments', function (Blueprint $table) {
            $table->decimal('current_balance', 15, 2)->default(0)->change();
            $table->decimal('lifetime_earned', 15, 2)->default(0)->change();
            $table->decimal('lifetime_redeemed', 15, 2)->default(0)->change();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->decimal('fee_fixed', 10, 2)->default(0)->change();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_monthly', 10, 2)->nullable()->change();
            $table->decimal('price_yearly', 10, 2)->nullable()->change();
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->change();
        });
    }
};
