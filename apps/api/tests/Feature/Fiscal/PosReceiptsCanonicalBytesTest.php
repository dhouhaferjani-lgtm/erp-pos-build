<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\POS\Domain\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 11 — `pos_receipts` becomes a projection row of `fiscal_events`.
 *
 * Spec §7.5 + §13 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`):
 * the existing `pos_receipts` chain columns (`fiscal_hash`, `previous_hash`,
 * `chain_sequence`) become **mirror columns** of the authoritative
 * `fiscal_events` row; they no longer advance independently. The new
 * `fiscal_event_id` UUID NULL UNIQUE FK is the linkage anchor — one
 * `pos_receipts` row per `SALE_RECEIPT` fiscal event — and is the
 * idempotency guard `PosCoreReceiptProjection::apply()` (Task 21) relies on.
 * `canonical_bytes` carries the verbatim canonical encoding from the device.
 *
 * Legacy rows (created before this migration) have NULL `fiscal_event_id` /
 * `canonical_bytes` — both columns are nullable for backward compatibility.
 */
final class PosReceiptsCanonicalBytesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_receipts_gains_canonical_bytes_and_fiscal_event_id_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'canonical_bytes'));
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'fiscal_event_id'));

        // Legacy chain columns retained as backward-compatible mirrors.
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'fiscal_hash'));
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'previous_hash'));
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'chain_sequence'));
    }

    public function test_model_fillable_includes_canonical_bytes_and_fiscal_event_id(): void
    {
        $fillable = (new Receipt)->getFillable();

        $this->assertContains('canonical_bytes', $fillable);
        $this->assertContains('fiscal_event_id', $fillable);
    }

    public function test_fiscal_event_id_unique_constraint_exists(): void
    {
        // Driver-portable: introspect Doctrine's schema manager for the
        // UNIQUE constraint name added in this migration. Insertion-based
        // testing would require seeding ~6 FK parent rows (tenants, companies,
        // locations, pos_terminals, users…); schema introspection pins the
        // contract directly and runs on both SQLite and PostgreSQL.
        $indexes = Schema::getIndexes('pos_receipts');
        $names = array_map(static fn (array $i) => (string) ($i['name'] ?? ''), $indexes);

        $this->assertContains(
            'pos_receipts_fiscal_event_id_unique',
            $names,
            'UNIQUE index pos_receipts_fiscal_event_id_unique missing — '
                .'PosCoreReceiptProjection (Task 21) idempotency guard would not be enforced',
        );

        // The matching index must cover exactly the `fiscal_event_id` column
        // and be flagged as unique.
        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === 'pos_receipts_fiscal_event_id_unique') {
                $this->assertTrue((bool) ($index['unique'] ?? false));
                $this->assertSame(['fiscal_event_id'], array_values($index['columns'] ?? []));

                return;
            }
        }
    }

    public function test_fiscal_event_id_fk_to_fiscal_events_exists_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conname = ? AND contype = 'f'",
            ['pos_receipts_fiscal_event_id_fk'],
        );

        $this->assertNotNull(
            $row,
            'FK pos_receipts_fiscal_event_id_fk → fiscal_events(id) missing on PostgreSQL',
        );
    }

    public function test_canonical_bytes_is_bytea_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT data_type, is_nullable FROM information_schema.columns
             WHERE table_name = ? AND column_name = ?',
            ['pos_receipts', 'canonical_bytes'],
        );

        $this->assertNotNull($row);
        $this->assertSame('bytea', $row->data_type);
        $this->assertSame('YES', $row->is_nullable, 'canonical_bytes must be nullable for legacy-row compatibility');
    }

    public function test_fiscal_event_id_is_nullable_for_legacy_rows_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT data_type, is_nullable FROM information_schema.columns
             WHERE table_name = ? AND column_name = ?',
            ['pos_receipts', 'fiscal_event_id'],
        );

        $this->assertNotNull($row);
        $this->assertSame('uuid', $row->data_type);
        $this->assertSame('YES', $row->is_nullable);
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('FK / catalog introspection only on PostgreSQL');
        }
    }
}
