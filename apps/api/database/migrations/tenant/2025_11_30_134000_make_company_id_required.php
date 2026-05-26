<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.2.2: Make company_id columns required.
 *
 * After the data migration has populated company_id for all existing records,
 * we can now make these columns non-nullable to enforce the constraint.
 *
 * IMPORTANT: This migration MUST run AFTER 2025_11_30_133000_migrate_tenant_data_to_companies
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Partners table
        Schema::table('partners', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Products table
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Vehicles table
        Schema::table('vehicles', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Documents table
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Accounts table
        Schema::table('accounts', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Journal entries table
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Payment methods table
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Payment repositories table
        Schema::table('payment_repositories', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Payment instruments table
        Schema::table('payment_instruments', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Stock levels table
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });

        // Stock movements table
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert all columns back to nullable
        Schema::table('partners', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('payment_repositories', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('payment_instruments', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('stock_levels', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
        });
    }
};
