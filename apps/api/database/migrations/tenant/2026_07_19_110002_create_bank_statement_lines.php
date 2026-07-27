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
        if (Schema::hasTable('bank_statement_lines')) {
            return;
        }

        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('bank_statement_id');
            $table->uuid('payment_repository_id');
            $table->unsignedInteger('line_number');
            $table->date('value_date');
            $table->date('booking_date')->nullable();
            $table->string('direction', 3);
            $table->decimal('amount', 15, 3);
            $table->string('reference')->nullable();
            $table->string('bank_transaction_id')->nullable();
            $table->text('label');
            $table->string('counterparty_hint')->nullable();
            $table->string('match_status', 30)->default('unmatched');
            $table->string('ignore_reason', 30)->nullable();
            $table->text('ignore_text')->nullable();
            $table->foreignUuid('location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();
            $table->char('fingerprint', 64);

            $table->unique(['bank_statement_id', 'line_number'], 'bank_statement_lines_statement_number_unique');
            $table->unique(['payment_repository_id', 'fingerprint'], 'bank_statement_lines_repository_fingerprint_unique');
            $table->index(['payment_repository_id', 'value_date']);
            $table->index('match_status');

            $table->foreign('bank_statement_id')
                ->references('id')
                ->on('bank_statements')
                ->cascadeOnDelete();
            $table->foreign('payment_repository_id')
                ->references('id')
                ->on('payment_repositories')
                ->restrictOnDelete();
            $table->foreign(
                ['bank_statement_id', 'payment_repository_id'],
                'bank_statement_lines_statement_repository_foreign',
            )
                ->references(['id', 'payment_repository_id'])
                ->on('bank_statements')
                ->cascadeOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_number_positive CHECK (line_number > 0)');
            DB::statement("ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_direction_check CHECK (direction IN ('in','out'))");
            DB::statement("ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_status_check CHECK (match_status IN ('unmatched','partial','matched','resolved_by_creation','ignored'))");
            DB::statement("ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_ignore_reason_check CHECK (ignore_reason IS NULL OR ignore_reason IN ('duplicate','informational','bank_error','out_of_scope','other'))");
            DB::statement(<<<'SQL'
                ALTER TABLE bank_statement_lines
                ADD CONSTRAINT bank_statement_lines_ignore_shape_check CHECK (
                    (
                        match_status = 'ignored'
                        AND ignore_reason IS NOT NULL
                        AND ignore_text IS NOT NULL
                        AND btrim(ignore_text) <> ''
                    ) OR (
                        match_status <> 'ignored'
                        AND ignore_reason IS NULL
                        AND ignore_text IS NULL
                    )
                )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
