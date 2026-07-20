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
        if (Schema::hasTable('bank_statement_match_executions')) {
            return;
        }

        Schema::create('bank_statement_match_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_statement_line_id')
                ->constrained('bank_statement_lines')
                ->restrictOnDelete();
            $table->string('action_type', 30);
            $table->string('action_key')->unique();
            $table->char('semantic_digest', 64);
            $table->string('target_type', 100)->nullable();
            $table->uuid('target_id')->nullable();
            $table->jsonb('produced_repository_movement_ids');
            $table->foreignUuid('executed_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('executed_at');

            $table->index(['bank_statement_line_id', 'action_type']);
            $table->index(['target_type', 'target_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE bank_statement_match_executions ADD CONSTRAINT bank_statement_executions_action_check CHECK (action_type IN ('outbound_clear','inbound_clear','expense_settle','acquirer_fee','create_expense','create_income'))");
            DB::statement("ALTER TABLE bank_statement_match_executions ADD CONSTRAINT bank_statement_executions_digest_check CHECK (semantic_digest ~ '^[0-9a-f]{64}$')");
            DB::statement('ALTER TABLE bank_statement_match_executions ADD CONSTRAINT bank_statement_executions_target_pair_check CHECK ((target_type IS NULL) = (target_id IS NULL))');
            DB::statement("ALTER TABLE bank_statement_match_executions ADD CONSTRAINT bank_statement_executions_movements_array_check CHECK (jsonb_typeof(produced_repository_movement_ids) = 'array')");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_bank_statement_match_execution_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'bank_statement_match_executions are immutable provenance';
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS bank_statement_match_executions_update_reject ON bank_statement_match_executions;
                CREATE TRIGGER bank_statement_match_executions_update_reject
                BEFORE UPDATE ON bank_statement_match_executions
                FOR EACH ROW EXECUTE FUNCTION reject_bank_statement_match_execution_mutation();

                DROP TRIGGER IF EXISTS bank_statement_match_executions_delete_reject ON bank_statement_match_executions;
                CREATE TRIGGER bank_statement_match_executions_delete_reject
                BEFORE DELETE ON bank_statement_match_executions
                FOR EACH ROW EXECUTE FUNCTION reject_bank_statement_match_execution_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_match_executions');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS reject_bank_statement_match_execution_mutation()');
        }
    }
};
