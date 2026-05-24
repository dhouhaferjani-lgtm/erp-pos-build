<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    public function test_duplicate_fiscal_event_id_insert_is_rejected(): void
    {
        // The plan's red test (§Task 11 step 1) requires proving the write
        // path rejects duplicate linkage, not just that the constraint exists
        // in metadata. The pattern matches PreflightFiscalGateCommandTest:
        // explicit factory chain for the ~5 FK parents
        // (tenants/companies/locations/users/pos_terminals) so the inner
        // factories don't try to recurse and leave NULL tenant_id columns.
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $eventId = $this->insertFiscalEvent();

        Receipt::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'fiscal_event_id' => $eventId,
        ]);

        $this->expectException(QueryException::class);
        // PosCoreReceiptProjection (Task 21) relies on this UNIQUE for the
        // safe-to-re-run idempotency guard:
        //   if (PosReceipt::where('fiscal_event_id', $event->id)->exists()) return;
        // Without runtime rejection, a buggy projector could double-project.
        Receipt::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'fiscal_event_id' => $eventId,
        ]);
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

    /**
     * Insert a minimal `fiscal_events` row matching the Task 7 schema and
     * return its id. Used by the duplicate-FK insert test so the FK on
     * `pos_receipts.fiscal_event_id` is satisfied on PG.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertFiscalEvent(array $overrides = []): string
    {
        $defaults = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => Str::uuid()->toString(),
            'company_id' => Str::uuid()->toString(),
            'terminal_id' => Str::uuid()->toString(),
            'operator_id' => Str::uuid()->toString(),
            'event_type' => 'SALE_RECEIPT',
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => now()->toDateTimeString(),
            'business_date' => now()->toDateString(),
            'server_received_at' => now()->toDateTimeString(),
            'canonical_bytes' => '{}',
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => str_repeat('a', 64),
        ];
        $row = array_merge($defaults, $overrides);
        DB::table('fiscal_events')->insert($row);

        return (string) $row['id'];
    }
}
