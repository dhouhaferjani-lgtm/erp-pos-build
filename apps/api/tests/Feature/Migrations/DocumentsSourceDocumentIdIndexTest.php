<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ReversibleTenantMigration;
use Throwable;

/**
 * Proof for triage lane F4 (finding DS-5): `documents.source_document_id`
 * carries the r2f4 correcting-entry hot path but shipped with no index —
 * `2025_11_30_080000_create_documents_table.php:33` declares it as a bare
 * `uuid()->nullable()`, and it is the ONLY mention of the column in all
 * tenant migrations.
 *
 * WHY no RefreshDatabase:
 *   The migration under test declares `public $withinTransaction = false;`
 *   and runs `CREATE INDEX CONCURRENTLY` / `DROP INDEX CONCURRENTLY`.
 *   PostgreSQL refuses CONCURRENTLY inside an open transaction, and
 *   RefreshDatabase wraps every test in one. This is the documented
 *   escape hatch: `Tests\Traits\ProvesTenantMigrationRoundTrip` fails fast
 *   on `$withinTransaction === false` and points at the direct
 *   require-and-call pattern of `T2MigrationRollbackTest`, which is what
 *   this test follows.
 *
 * PostgreSQL-only: skipped on SQLite, which is exactly the contract — the
 * migration is pgsql-guarded and is a deliberate no-op on every other
 * driver, so there is nothing about SQLite for this test to assert.
 */
class DocumentsSourceDocumentIdIndexTest extends TestCase
{
    private const MIGRATION_FILE = '2026_08_23_100000_add_source_document_id_index_to_documents.php';

    private const INDEX_NAME = 'documents_source_document_id_idx';

    /**
     * The base `Migration` class declares neither `up()` nor `down()` — the
     * runner calls them by duck typing — so the intersection with
     * {@see ReversibleTenantMigration} is what lets static analysis see them.
     *
     * @var Migration&ReversibleTenantMigration
     */
    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'DocumentsSourceDocumentIdIndexTest requires PostgreSQL: the migration is '
                .'pgsql-guarded, and CONCURRENTLY index operations cannot run on SQLite or '
                .'inside a transaction (RefreshDatabase).'
            );
        }

        $path = database_path('migrations/tenant/'.self::MIGRATION_FILE);
        $this->assertFileExists($path, 'Tenant migration file missing: '.self::MIGRATION_FILE);

        /** @var Migration&ReversibleTenantMigration $instance */
        $instance = require $path;
        $this->migration = $instance;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Single test method on purpose: the phases must run sequentially with no
     * state reset between them (CONCURRENTLY DDL cannot be transaction-isolated,
     * so splitting the phases across tests would need ordered tests instead).
     *
     *   pre-condition present -> down() -> absent -> up() -> present
     *   -> up() AGAIN -> still present, exactly one index, no throw
     */
    public function test_source_document_id_index_round_trips_and_reapplies_idempotently(): void
    {
        // ── Phase 0: self-heal — guarantee a fully-migrated baseline ─────────
        Artisan::call('migrate', ['--force' => true, '--path' => 'database/migrations/tenant']);

        $this->assertIndexPresent('as a pre-condition (the baseline migrate already applied it)');

        // ── Phase 1: rollback removes it ─────────────────────────────────────
        $this->migration->down();
        $this->assertIndexAbsent('after down()');

        // ── Phase 2: forward apply restores it ───────────────────────────────
        $this->migration->up();
        $this->assertIndexPresent('after the forward-apply proof');

        // ── Phase 3: re-running up() is a NO-OP, not an error ────────────────
        // This is the `CREATE INDEX CONCURRENTLY IF NOT EXISTS` guarantee. A
        // bare CREATE INDEX would raise SQLSTATE 42P07 (duplicate_table) here.
        try {
            $this->migration->up();
        } catch (Throwable $e) {
            $this->fail(
                're-running up() on an already-applied index migration must be a no-op '
                .'(CREATE INDEX CONCURRENTLY IF NOT EXISTS), but it threw '
                .$e::class.': '.$e->getMessage()
            );
        }

        $this->assertIndexPresent('after the idempotent re-apply');
        $this->assertSame(
            1,
            $this->indexRowCount(),
            'Re-running up() must not create a second index on documents.source_document_id.'
        );

        // ── Phase 4: leave the DB healthy for subsequent runs ────────────────
        Artisan::call('migrate', ['--force' => true, '--path' => 'database/migrations/tenant']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function assertIndexPresent(string $context): void
    {
        $definition = $this->indexDefinition();

        $this->assertNotNull(
            $definition,
            "Index '".self::INDEX_NAME."' must exist on `documents` {$context}."
        );

        $this->assertStringContainsString(
            'source_document_id',
            $definition,
            "Index '".self::INDEX_NAME."' must key on source_document_id {$context}."
        );

        // Partial by design: every predicate on this column is `=` / `IN` /
        // `IS NOT NULL` (13 of them across app/), and NOT ONE is
        // `whereNull('source_document_id')` — so excluding the NULL rows
        // (the overwhelming majority: standalone quotes/invoices with no
        // source) costs no query coverage and keeps the index small.
        $this->assertStringContainsString(
            'IS NOT NULL',
            $definition,
            "Index '".self::INDEX_NAME."' must be partial (WHERE source_document_id IS NOT NULL) {$context}."
        );
    }

    private function assertIndexAbsent(string $context): void
    {
        $this->assertNull(
            $this->indexDefinition(),
            "Index '".self::INDEX_NAME."' must be gone {$context}."
        );
    }

    private function indexDefinition(): ?string
    {
        $row = DB::selectOne(
            'SELECT indexdef FROM pg_indexes
              WHERE schemaname = current_schema()
                AND tablename = ?
                AND indexname = ?',
            ['documents', self::INDEX_NAME]
        );

        if ($row === null) {
            return null;
        }

        /** @var object{indexdef: string} $row */
        return $row->indexdef;
    }

    private function indexRowCount(): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS total FROM pg_indexes
              WHERE schemaname = current_schema()
                AND tablename = ?
                AND indexname = ?',
            ['documents', self::INDEX_NAME]
        );

        /** @var object{total: int|string} $row */
        return (int) $row->total;
    }
}
