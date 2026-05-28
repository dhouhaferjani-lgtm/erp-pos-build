<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.1.6: Add company_id to existing tables.
 *
 * This migration changes the data scoping model from Tenant to Company.
 * In the new architecture:
 * - Tenant = Account holder (person who pays subscription)
 * - Company = Legal entity (where business data is scoped)
 * - Location = Physical place (for stock and POS operations)
 *
 * Tables modified:
 * - partners: customers/suppliers are now company-scoped
 * - products: catalog is company-specific
 * - vehicles: vehicles belong to a company's customers
 * - documents: invoices, quotes, etc. belong to company + optionally location
 * - accounts: chart of accounts per company
 * - journal_entries: GL entries are company-scoped
 * - payment_methods: payment configuration per company
 * - payment_repositories: cash registers, bank accounts per company
 * - payment_instruments: checks, vouchers per company
 * - payments: payments are company-scoped
 *
 * Note: company_id is nullable initially to allow gradual data migration.
 * A future migration will make it required after data is migrated.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Partners (customers, suppliers)
        Schema::table('partners', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'type'], 'idx_partners_company_type');
            $table->index(['company_id', 'name'], 'idx_partners_company_name');
        });

        // Products
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'type'], 'idx_products_company_type');
            $table->index(['company_id', 'is_active'], 'idx_products_company_active');
            $table->index(['company_id', 'sku'], 'idx_products_company_sku');
        });

        // Vehicles
        Schema::table('vehicles', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'license_plate'], 'idx_vehicles_company_plate');
            $table->index(['company_id', 'partner_id'], 'idx_vehicles_company_partner');
        });

        // Documents (invoices, quotes, orders, etc.)
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->uuid('location_id')->nullable()->after('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('set null');
            $table->index(['company_id', 'type', 'status'], 'idx_documents_company_type_status');
            $table->index(['company_id', 'document_date'], 'idx_documents_company_date');
            $table->index(['company_id', 'partner_id'], 'idx_documents_company_partner');
            $table->index('location_id', 'idx_documents_location');
        });

        // Accounts (chart of accounts)
        Schema::table('accounts', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'code'], 'idx_accounts_company_code');
            $table->index(['company_id', 'type'], 'idx_accounts_company_type');
        });

        // Journal entries
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'entry_date'], 'idx_journal_entries_company_date');
            $table->index(['company_id', 'status'], 'idx_journal_entries_company_status');
        });

        // Payment methods
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'code'], 'idx_payment_methods_company_code');
            $table->index(['company_id', 'is_active'], 'idx_payment_methods_company_active');
        });

        // Payment repositories (cash registers, safes, bank accounts)
        Schema::table('payment_repositories', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'code'], 'idx_payment_repos_company_code');
            $table->index(['company_id', 'type'], 'idx_payment_repos_company_type');
        });

        // Payment instruments (checks, vouchers, etc.)
        Schema::table('payment_instruments', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'status'], 'idx_payment_instr_company_status');
            $table->index(['company_id', 'maturity_date'], 'idx_payment_instr_company_maturity');
        });

        // Payments
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'payment_date'], 'idx_payments_company_date');
            $table->index(['company_id', 'status'], 'idx_payments_company_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Payments
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_payments_company_date');
            $table->dropIndex('idx_payments_company_status');
            $table->dropColumn('company_id');
        });

        // Payment instruments
        Schema::table('payment_instruments', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_payment_instr_company_status');
            $table->dropIndex('idx_payment_instr_company_maturity');
            $table->dropColumn('company_id');
        });

        // Payment repositories
        Schema::table('payment_repositories', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_payment_repos_company_code');
            $table->dropIndex('idx_payment_repos_company_type');
            $table->dropColumn('company_id');
        });

        // Payment methods
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_payment_methods_company_code');
            $table->dropIndex('idx_payment_methods_company_active');
            $table->dropColumn('company_id');
        });

        // Journal entries
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_journal_entries_company_date');
            $table->dropIndex('idx_journal_entries_company_status');
            $table->dropColumn('company_id');
        });

        // Accounts
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_accounts_company_code');
            $table->dropIndex('idx_accounts_company_type');
            $table->dropColumn('company_id');
        });

        // Documents
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['location_id']);
            $table->dropIndex('idx_documents_company_type_status');
            $table->dropIndex('idx_documents_company_date');
            $table->dropIndex('idx_documents_company_partner');
            $table->dropIndex('idx_documents_location');
            $table->dropColumn(['company_id', 'location_id']);
        });

        // Vehicles
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_vehicles_company_plate');
            $table->dropIndex('idx_vehicles_company_partner');
            $table->dropColumn('company_id');
        });

        // Products
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_products_company_type');
            $table->dropIndex('idx_products_company_active');
            $table->dropIndex('idx_products_company_sku');
            $table->dropColumn('company_id');
        });

        // Partners
        Schema::table('partners', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_partners_company_type');
            $table->dropIndex('idx_partners_company_name');
            $table->dropColumn('company_id');
        });
    }
};
