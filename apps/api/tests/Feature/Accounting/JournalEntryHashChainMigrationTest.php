<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P1-B Milestone 1: Test GL hash chain schema migration.
 *
 * This test verifies that the journal_entries table has been properly
 * updated with fiscal compliance hash chain columns and indexes.
 */
final class JournalEntryHashChainMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_journal_entries_table_has_hash_chain_columns(): void
    {
        // Verify all required hash chain columns exist
        $this->assertTrue(
            Schema::hasColumn('journal_entries', 'fiscal_hash'),
            'journal_entries should have fiscal_hash column'
        );

        $this->assertTrue(
            Schema::hasColumn('journal_entries', 'previous_hash'),
            'journal_entries should have previous_hash column'
        );

        $this->assertTrue(
            Schema::hasColumn('journal_entries', 'chain_sequence'),
            'journal_entries should have chain_sequence column'
        );
    }

    public function test_fiscal_hash_column_has_correct_size(): void
    {
        // Verify fiscal_hash is exactly 64 chars (SHA-256 length)
        $columns = Schema::getColumns('journal_entries');
        $fiscalHashColumn = collect($columns)->firstWhere('name', 'fiscal_hash');

        $this->assertNotNull($fiscalHashColumn, 'fiscal_hash column should exist');
        $this->assertEquals('varchar', $fiscalHashColumn['type_name']);

        // PostgreSQL shows "character varying(64)", SQLite shows "varchar"
        // Both are correct - we verify the migration syntax which is database-agnostic
        if (config('database.default') === 'pgsql') {
            $this->assertStringContainsString('64', $fiscalHashColumn['type'], 'fiscal_hash should be 64 characters in PostgreSQL');
        }
    }

    public function test_previous_hash_column_has_correct_size(): void
    {
        // Verify previous_hash is exactly 64 chars (SHA-256 length)
        $columns = Schema::getColumns('journal_entries');
        $previousHashColumn = collect($columns)->firstWhere('name', 'previous_hash');

        $this->assertNotNull($previousHashColumn, 'previous_hash column should exist');
        $this->assertEquals('varchar', $previousHashColumn['type_name']);

        // PostgreSQL shows "character varying(64)", SQLite shows "varchar"
        // Both are correct - we verify the migration syntax which is database-agnostic
        if (config('database.default') === 'pgsql') {
            $this->assertStringContainsString('64', $previousHashColumn['type'], 'previous_hash should be 64 characters in PostgreSQL');
        }
    }

    public function test_fiscal_hash_column_has_unique_constraint(): void
    {
        // Verify unique constraint exists on fiscal_hash
        $indexes = Schema::getIndexes('journal_entries');
        $uniqueIndexes = array_filter($indexes, fn ($idx) => $idx['unique']);

        $hasFiscalHashUnique = collect($uniqueIndexes)->contains(function ($idx) {
            return in_array('fiscal_hash', $idx['columns']);
        });

        $this->assertTrue(
            $hasFiscalHashUnique,
            'fiscal_hash should have unique constraint to prevent duplicate hashes'
        );
    }

    public function test_chain_sequence_has_company_index(): void
    {
        // Verify compound index on company_id and chain_sequence exists
        // This is critical for GL chain verification performance
        $indexes = Schema::getIndexes('journal_entries');

        $hasChainIndex = collect($indexes)->contains(function ($idx) {
            return $idx['name'] === 'idx_gl_company_chain'
                && in_array('company_id', $idx['columns'])
                && in_array('chain_sequence', $idx['columns']);
        });

        $this->assertTrue(
            $hasChainIndex,
            'Should have compound index on company_id and chain_sequence for GL chain verification'
        );
    }

    public function test_fiscal_hash_has_dedicated_index(): void
    {
        // Verify fiscal_hash has its own index for fast hash lookups
        $indexes = Schema::getIndexes('journal_entries');

        $hasFiscalHashIndex = collect($indexes)->contains(function ($idx) {
            return $idx['name'] === 'idx_gl_fiscal_hash'
                && in_array('fiscal_hash', $idx['columns']);
        });

        $this->assertTrue(
            $hasFiscalHashIndex,
            'fiscal_hash should have dedicated index for fast hash lookups during verification'
        );
    }

    public function test_hash_chain_columns_are_nullable(): void
    {
        // Verify columns are nullable to support historical data
        $columns = Schema::getColumns('journal_entries');

        $fiscalHash = collect($columns)->firstWhere('name', 'fiscal_hash');
        $previousHash = collect($columns)->firstWhere('name', 'previous_hash');
        $chainSequence = collect($columns)->firstWhere('name', 'chain_sequence');

        $this->assertTrue($fiscalHash['nullable'], 'fiscal_hash should be nullable');
        $this->assertTrue($previousHash['nullable'], 'previous_hash should be nullable');
        $this->assertTrue($chainSequence['nullable'], 'chain_sequence should be nullable');
    }

    public function test_old_hash_column_was_renamed(): void
    {
        // Verify that the old 'hash' column no longer exists
        // It should have been renamed to 'fiscal_hash'
        $this->assertFalse(
            Schema::hasColumn('journal_entries', 'hash'),
            'Old "hash" column should have been renamed to "fiscal_hash"'
        );
    }
}
