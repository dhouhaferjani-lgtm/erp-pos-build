<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add performance indexes for accounting reports:
     * - Trial Balance: Aggregates debits/credits by account
     * - General Ledger: Chronological journal lines with running balances
     * - Profit & Loss: Revenue/expense accounts within date range
     * - Balance Sheet: Asset/liability/equity balances as of a date
     *
     * These indexes optimize the most common query patterns in financial reporting.
     */
    public function up(): void
    {
        // Drop existing indexes if they exist (from failed migrations)
        $this->dropIndexIfExists('journal_entries', 'idx_je_company_status_date');
        $this->dropIndexIfExists('journal_lines', 'idx_jl_account_entry');
        $this->dropIndexIfExists('journal_lines', 'idx_jl_partner_entry');
        $this->dropIndexIfExists('accounts', 'idx_accounts_company_type');
        $this->dropIndexIfExists('accounts', 'idx_accounts_parent');

        // Index for journal_entries table
        // Optimizes: Status-based filtering with date ranges
        // Used by: All reports (filter by posted status and date)
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(
                ['company_id', 'status', 'entry_date'],
                'idx_je_company_status_date'
            );
        });

        // Indexes for journal_lines table
        // Optimizes: Account-based aggregations and partner balances
        Schema::table('journal_lines', function (Blueprint $table) {
            // Used by: Trial Balance, P&L, Balance Sheet (aggregate by account)
            $table->index(
                ['account_id', 'journal_entry_id'],
                'idx_jl_account_entry'
            );

            // Used by: Partner subledger, General Ledger with partner filter
            $table->index(
                ['partner_id', 'journal_entry_id'],
                'idx_jl_partner_entry'
            );
        });

        // Indexes for accounts table
        // Optimizes: Account type filtering and hierarchy traversal
        Schema::table('accounts', function (Blueprint $table) {
            // Used by: P&L (revenue/expense), Balance Sheet (asset/liability/equity)
            $table->index(
                ['company_id', 'type', 'is_active'],
                'idx_accounts_company_type'
            );

            // Used by: Account hierarchy building (parent-child relationships)
            $table->index('parent_id', 'idx_accounts_parent');
        });
    }

    /**
     * Drop an index if it exists.
     *
     * @param string $table
     * @param string $indexName
     * @return void
     */
    private function dropIndexIfExists(string $table, string $indexName): void
    {
        \DB::statement("DROP INDEX IF EXISTS {$indexName}");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('idx_je_company_status_date');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex('idx_jl_account_entry');
            $table->dropIndex('idx_jl_partner_entry');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex('idx_accounts_company_type');
            $table->dropIndex('idx_accounts_parent');
        });
    }
};
