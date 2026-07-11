<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hard DB backstop against a forked fiscal hash chain (Treasury spine Wave B gate).
 *
 * The per-company advisory lock taken in GeneralLedgerService::sealAndPersistEntry
 * (Task 7) only serializes chain-sequence allocation inside an explicit
 * transaction — on the legacy autocommit posting paths it degrades to a no-op.
 * The existing idx_gl_company_chain index is NON-unique, so nothing at the DB
 * level prevents two concurrent posts from allocating the SAME chain_sequence
 * and forking the chain.
 *
 * This partial UNIQUE index closes that gap: it enforces that, per company, no
 * two POSTED entries can share a chain_sequence. Drafts (chain_sequence IS NULL)
 * are excluded so multiple drafts remain legal — only sealed entries carry a
 * chain_sequence and must be globally unique within the company chain.
 *
 * Partial UNIQUE indexes are supported natively by both PostgreSQL and modern
 * SQLite (3.8.0+), so the index is created on both drivers.
 *
 * DEPLOYMENT NOTE: on a production DB that already contains forked/duplicate
 * (company_id, chain_sequence) rows from the pre-existing legacy race, THIS
 * MIGRATION WILL FAIL at CREATE INDEX. The owner must dedupe/reseal the
 * affected chain segment BEFORE applying this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uniq_je_company_chain_sequence
                ON journal_entries (company_id, chain_sequence)
                WHERE chain_sequence IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_je_company_chain_sequence');
    }
};
