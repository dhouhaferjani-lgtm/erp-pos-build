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

        // T6 Phase 0b: the `plans` + `tenant_subscriptions` widening moved to the
        // central migration 2026_03_24_300001 (those tables live in the central
        // database and cannot be altered from inside a tenant database).
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

        // `plans` + `tenant_subscriptions` reverted by the central migration
        // 2026_03_24_300001 (see up()).
    }
};
