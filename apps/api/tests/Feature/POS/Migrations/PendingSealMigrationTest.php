<?php

declare(strict_types=1);

namespace Tests\Feature\POS\Migrations;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PendingSealMigrationTest extends TestCase
{
    public function test_pos_receipts_fiscal_hash_is_nullable(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $row = DB::selectOne(
            'SELECT is_nullable FROM information_schema.columns '.
            "WHERE table_name = 'pos_receipts' AND column_name = 'fiscal_hash'"
        );
        $this->assertSame('YES', $row->is_nullable);
    }

    public function test_pos_receipts_chain_sequence_is_nullable(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $row = DB::selectOne(
            'SELECT is_nullable FROM information_schema.columns '.
            "WHERE table_name = 'pos_receipts' AND column_name = 'chain_sequence'"
        );
        $this->assertSame('YES', $row->is_nullable);
    }

    public function test_pos_terminals_has_fiscal_schema_version_default_2(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $row = DB::selectOne(
            'SELECT column_default FROM information_schema.columns '.
            "WHERE table_name = 'pos_terminals' AND column_name = 'fiscal_schema_version'"
        );
        $this->assertNotNull($row);
        $this->assertSame('2', trim((string) $row->column_default, "'"));
    }

    public function test_fiscal_status_check_constraint_includes_pending_seal(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_constraint is Postgres-specific');
        }

        $row = DB::selectOne(
            'SELECT conname, pg_get_constraintdef(oid) AS def '.
            'FROM pg_constraint '.
            "WHERE conrelid = 'pos_receipts'::regclass ".
            "AND contype = 'c' ".
            "AND conname LIKE '%fiscal_status%'"
        );
        $this->assertNotNull($row, 'fiscal_status check constraint missing');
        $this->assertStringContainsString('pending_seal', $row->def);
        $this->assertStringContainsString('fiscalized', $row->def);
        $this->assertStringContainsString('voided', $row->def);
    }

    public function test_immutability_trigger_allows_pending_seal_to_fiscalized(): void
    {
        $this->markTestIncomplete(
            'Will be enabled in Task 5 once a pending_seal receipt can be created via the factory'
        );
    }

    public function test_immutability_trigger_rejects_fiscalized_to_pending_seal(): void
    {
        $this->markTestIncomplete(
            'Will be enabled in Task 5 once a fiscalized receipt is reachable'
        );
    }
}
