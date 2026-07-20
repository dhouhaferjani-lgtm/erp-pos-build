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
        if (Schema::hasTable('bank_statements')) {
            return;
        }

        Schema::create('bank_statements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->foreignUuid('payment_repository_id')
                ->constrained('payment_repositories')
                ->restrictOnDelete();
            $table->char('currency', 3);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 15, 3);
            $table->decimal('closing_balance', 15, 3);
            $table->string('status', 20);
            $table->char('source_file_sha256', 64);
            $table->string('source_file_path', 2048);
            $table->foreignUuid('parser_profile_id')
                ->nullable()
                ->constrained('statement_import_profiles')
                ->nullOnDelete();
            $table->foreignUuid('imported_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('imported_at');
            $table->timestampsTz();

            $table->unique(['id', 'payment_repository_id'], 'bank_statements_id_repository_unique');
            $table->index(['tenant_id', 'company_id']);
            $table->index(['payment_repository_id', 'period_start']);
            $table->index('status');
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX bank_statements_repository_file_unique
                ON bank_statements (payment_repository_id, source_file_sha256)
                WHERE status <> 'voided'
                SQL);
        } else {
            Schema::table('bank_statements', function (Blueprint $table): void {
                $table->unique(
                    ['payment_repository_id', 'source_file_sha256'],
                    'bank_statements_repository_file_unique',
                );
            });
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_status_check CHECK (status IN ('imported','reconciling','reconciled','voided'))");
            DB::statement('ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_period_check CHECK (period_end >= period_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
