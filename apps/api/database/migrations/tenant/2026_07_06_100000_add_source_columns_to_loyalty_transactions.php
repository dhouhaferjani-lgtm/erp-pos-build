<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add dedicated source_type/source_id columns + a partial unique index so
     * earn dedupe is enforced atomically at the DB rather than via a TOCTOU
     * check-then-insert (two queued earn paths could race the same receipt →
     * double credit).
     *
     * phpunit runs on SQLite :memory: (phpunit.xml); production/staging is PG.
     * The backfill SQL diverges by driver; the partial CREATE UNIQUE INDEX
     * statement is valid on both engines and is shared.
     */
    public function up(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 64)->nullable();
        });

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        // Backfill from metadata. PG: jsonb_exists avoids PDO '?' operator
        // escaping and metadata->>'x' reads the text value. SQLite: json_extract.
        if ($isSqlite) {
            DB::statement(<<<'SQL'
                UPDATE loyalty_transactions
                SET source_type = json_extract(metadata, '$.source_type'),
                    source_id   = json_extract(metadata, '$.source_id')
                WHERE json_extract(metadata, '$.source_type') IS NOT NULL
            SQL);
        } else {
            DB::statement(<<<'SQL'
                UPDATE loyalty_transactions
                SET source_type = metadata->>'source_type', source_id = metadata->>'source_id'
                WHERE jsonb_exists(metadata, 'source_type')
            SQL);
        }

        // Pre-existing double-earns (the very bug this fixes) would break the
        // unique index build: keep the EARLIEST earn per (enrollment, source),
        // null the columns on later duplicates. Rows/balances are untouched
        // (audit stays intact — metadata still carries the original source refs).
        if ($isSqlite) {
            DB::statement(<<<'SQL'
                UPDATE loyalty_transactions
                SET source_type = NULL, source_id = NULL
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id, row_number() OVER (
                            PARTITION BY enrollment_id, source_type, source_id
                            ORDER BY created_at, id
                        ) AS rn
                        FROM loyalty_transactions
                        WHERE transaction_type = 'earn' AND source_type IS NOT NULL
                    ) ranked
                    WHERE ranked.rn > 1
                )
            SQL);
        } else {
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id, row_number() OVER (
                        PARTITION BY enrollment_id, source_type, source_id
                        ORDER BY created_at, id
                    ) AS rn
                    FROM loyalty_transactions
                    WHERE transaction_type = 'earn' AND source_type IS NOT NULL
                )
                UPDATE loyalty_transactions t
                SET source_type = NULL, source_id = NULL
                FROM ranked r WHERE t.id = r.id AND r.rn > 1
            SQL);
        }

        // NOTE: the index is PER-ENROLLMENT while the earnPoints pre-check
        // (findBySourceDocument) is GLOBAL — the index only fires on a true
        // same-enrollment concurrent race (the defect being fixed). A future
        // multi-program-earn change must relax the global pre-check; this index
        // already supports per-enrollment earns.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX loyalty_txn_earn_source_unique
            ON loyalty_transactions (enrollment_id, source_type, source_id)
            WHERE transaction_type = 'earn' AND source_type IS NOT NULL
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS loyalty_txn_earn_source_unique');
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
