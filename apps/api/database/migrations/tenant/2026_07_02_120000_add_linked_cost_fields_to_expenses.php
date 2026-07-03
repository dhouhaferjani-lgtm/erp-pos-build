<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table): void {
            $table->string('expense_kind', 20)->default('generic')->after('vendor_name');
        });

        Schema::table('document_additional_costs', function (Blueprint $table): void {
            $table->string('application_path', 20)->default('landed_cost')->after('expense_document_id');
            $table->string('split_method', 20)->default('by_value')->after('application_path');
            $table->timestampTz('applied_at')->nullable()->after('split_method');
            $table->timestampTz('reversed_at')->nullable()->after('applied_at');
            $table->uuid('reverses_cost_id')->nullable()->after('reversed_at');
            $table->index('expense_document_id', 'document_additional_costs_expense_document_id_index');
            $table->index('reverses_cost_id', 'document_additional_costs_reverses_cost_id_index');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uniq_je_linked_cost
                ON journal_entries (source_type, source_id)
                WHERE source_type = 'linked_cost_capitalization'
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uniq_je_linked_cost_rev
                ON journal_entries (source_type, source_id)
                WHERE source_type = 'linked_cost_capitalization_reversal'
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_je_linked_cost_rev');
        DB::statement('DROP INDEX IF EXISTS uniq_je_linked_cost');

        Schema::table('document_additional_costs', function (Blueprint $table): void {
            $table->dropIndex('document_additional_costs_expense_document_id_index');
            $table->dropIndex('document_additional_costs_reverses_cost_id_index');
            $table->dropColumn([
                'application_path',
                'split_method',
                'applied_at',
                'reversed_at',
                'reverses_cost_id',
            ]);
        });

        Schema::table('expense_metadata', function (Blueprint $table): void {
            $table->dropColumn('expense_kind');
        });
    }
};
