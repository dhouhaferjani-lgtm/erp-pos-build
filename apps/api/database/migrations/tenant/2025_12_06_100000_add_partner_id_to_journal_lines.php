<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            // Partner reference - nullable because not all GL entries involve partners
            // (e.g., depreciation, payroll, bank fees)
            $table->uuid('partner_id')->nullable()->after('account_id');

            // Foreign key to partners table
            $table->foreign('partner_id')
                ->references('id')
                ->on('partners')
                ->onDelete('restrict'); // Don't allow deleting partners with GL history

            // Index for partner balance queries
            $table->index(['partner_id', 'account_id'], 'journal_lines_partner_account_idx');

            // Index for subledger queries (all lines for an account with partners)
            $table->index(['account_id', 'partner_id'], 'journal_lines_account_partner_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropForeign(['partner_id']);
            $table->dropIndex('journal_lines_partner_account_idx');
            $table->dropIndex('journal_lines_account_partner_idx');
            $table->dropColumn('partner_id');
        });
    }
};
