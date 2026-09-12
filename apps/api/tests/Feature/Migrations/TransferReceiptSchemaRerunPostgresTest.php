<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TransferReceiptSchemaRerunPostgresTest extends TestCase
{
    use RefreshDatabase;

    private const COUNTERS = ['quantity_damaged', 'quantity_received', 'quantity_returned', 'quantity_written_off'];

    private const TABLES = ['stock_transfer_lines', 'stock_transfer_line_batch_allocations', 'stock_transfer_receipts', 'stock_transfer_receipt_lines', 'stock_transfer_receipt_line_lots'];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL catalogs and CHECK constraints are required.');
        }
    }

    public function test_the_four_counters_three_tables_and_named_checks_exist_after_the_first_run(): void
    {
        foreach (array_slice(self::TABLES, 0, 2) as $table) {
            self::assertSame(self::COUNTERS, DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', $table)->whereIn('column_name', self::COUNTERS)->orderBy('column_name')->pluck('column_name')->all());
            self::assertTrue(DB::table('pg_constraint')->where('conname', $table.'_received_within_sent')->exists());
        }
        foreach (array_slice(self::TABLES, 2) as $table) {
            self::assertTrue(Schema::hasTable($table));
        }
    }

    public function test_clean_rerun_adds_no_migration_row_and_the_catalog_is_identical(): void
    {
        $catalogBefore = $this->catalog();
        self::assertSame(5, DB::table('information_schema.tables')->where('table_schema', 'public')->whereIn('table_name', self::TABLES)->count());
        $migrationRowsBefore = DB::table('migrations')->orderBy('id')->get()->toJson();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        foreach (glob(database_path('migrations/tenant/2026_09_09_100*.php')) as $path) {
            (require $path)->up();
        }
        self::assertSame($migrationRowsBefore, DB::table('migrations')->orderBy('id')->get()->toJson());
        self::assertSame($catalogBefore, $this->catalog());
    }

    /** @return array<string, string> */
    private function catalog(): array
    {
        return [
            'columns' => DB::table('information_schema.columns')->select('table_name', 'column_name', 'data_type', 'is_nullable', 'column_default')->where('table_schema', 'public')->whereIn('table_name', self::TABLES)->orderBy('table_name')->orderBy('column_name')->get()->toJson(),
            'checks' => DB::table('pg_constraint')->join('pg_class', 'pg_class.oid', '=', 'pg_constraint.conrelid')->select('relname', 'conname', 'contype')->whereIn('relname', self::TABLES)->orderBy('relname')->orderBy('conname')->get()->toJson(),
            'indexes' => DB::table('pg_indexes')->select('tablename', 'indexname', 'indexdef')->where('schemaname', 'public')->whereIn('tablename', self::TABLES)->orderBy('tablename')->orderBy('indexname')->get()->toJson(),
        ];
    }
}
