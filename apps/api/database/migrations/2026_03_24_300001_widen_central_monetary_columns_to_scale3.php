<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T6 Phase 0b: scale-3 widening for the CENTRAL billing tables.
 *
 * Split out of the tenant migration 2026_03_24_300000 (which widens tenant
 * monetary columns). `plans` and `tenant_subscriptions` live in the central
 * database, so their widening must run as a central migration — it cannot run
 * inside a per-tenant database where those tables do not exist.
 */
return new class extends Migration
{
    public function up(): void
    {
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
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_monthly', 10, 2)->nullable()->change();
            $table->decimal('price_yearly', 10, 2)->nullable()->change();
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->change();
        });
    }
};
