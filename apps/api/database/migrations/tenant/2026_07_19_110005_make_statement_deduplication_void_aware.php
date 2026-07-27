<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->guardDriver();
        if (! Schema::hasTable('bank_statements') || ! Schema::hasTable('bank_statement_lines')) {
            return;
        }

        if (! Schema::hasColumn('bank_statement_lines', 'dedupe_active')) {
            Schema::table('bank_statement_lines', function (Blueprint $table): void {
                $table->boolean('dedupe_active')->default(true);
            });
        }
        DB::table('bank_statement_lines')
            ->whereExists(static function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('bank_statements')
                    ->whereColumn('bank_statements.id', 'bank_statement_lines.bank_statement_id')
                    ->where('bank_statements.status', 'voided');
            })
            ->update(['dedupe_active' => false]);

        $this->dropUniqueIndex('bank_statements', 'bank_statements_repository_file_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_statements_repository_file_unique
            ON bank_statements (payment_repository_id, source_file_sha256)
            WHERE status <> 'voided'
            SQL);
        $this->dropUniqueIndex('bank_statement_lines', 'bank_statement_lines_repository_fingerprint_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_statement_lines_repository_fingerprint_unique
            ON bank_statement_lines (payment_repository_id, fingerprint)
            WHERE dedupe_active
            SQL);
    }

    public function down(): void
    {
        $this->guardDriver();
        if (! Schema::hasTable('bank_statements') || ! Schema::hasTable('bank_statement_lines')) {
            return;
        }

        $this->dropUniqueIndex('bank_statement_lines', 'bank_statement_lines_repository_fingerprint_unique');
        $this->dropUniqueIndex('bank_statements', 'bank_statements_repository_file_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_statements_repository_file_unique
            ON bank_statements (payment_repository_id, source_file_sha256)
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_statement_lines_repository_fingerprint_unique
            ON bank_statement_lines (payment_repository_id, fingerprint)
            SQL);

        if (Schema::hasColumn('bank_statement_lines', 'dedupe_active')) {
            Schema::table('bank_statement_lines', function (Blueprint $table): void {
                $table->dropColumn('dedupe_active');
            });
        }
    }

    private function guardDriver(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Statement deduplication indexes do not support the {$driver} driver.");
        }
    }

    private function dropUniqueIndex(string $table, string $index): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$index}");
        }
        DB::statement("DROP INDEX IF EXISTS {$index}");
    }
};
