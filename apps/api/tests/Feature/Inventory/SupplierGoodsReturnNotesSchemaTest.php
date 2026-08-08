<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\ProvesTenantMigrationRoundTrip;

/**
 * DPA lane V8 — schema proof for the supplier goods-return note tables.
 *
 * The migration ships on a branch that auto-deploys `tenants:migrate` on push to
 * origin/dev with NO manual prerequisite, so it must be unattended-safe:
 * self-guarding on re-run, and cleanly reversible.
 */
final class SupplierGoodsReturnNotesSchemaTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    private const MIGRATION = '2026_08_08_160000_create_supplier_goods_return_notes_tables.php';

    public function test_supplier_goods_return_notes_migration_round_trips(): void
    {
        $this->assertTenantMigrationRoundTrips(
            self::MIGRATION,
            function (string $context): void {
                $this->assertTrue(
                    Schema::hasTable('supplier_goods_return_notes'),
                    "supplier_goods_return_notes must exist {$context}",
                );
                $this->assertTrue(
                    Schema::hasColumns('supplier_goods_return_notes', [
                        'id',
                        'tenant_id',
                        'company_id',
                        'supplier_credit_note_id',
                        'partner_id',
                        'note_number',
                        'status',
                        'location_id',
                        'reference',
                        'returned_at',
                        'created_by',
                        'confirmed_by',
                        'notes',
                        'created_at',
                        'updated_at',
                    ]),
                    "supplier_goods_return_notes must carry the full document shape {$context}",
                );
                $this->assertTrue(
                    Schema::hasTable('supplier_goods_return_note_lines'),
                    "supplier_goods_return_note_lines must exist {$context}",
                );
                $this->assertTrue(
                    Schema::hasColumns('supplier_goods_return_note_lines', [
                        'id',
                        'tenant_id',
                        'company_id',
                        'supplier_goods_return_note_id',
                        'po_line_id',
                        'goods_receipt_id',
                        'goods_receipt_line_id',
                        'product_id',
                        'variant_id',
                        'kind',
                        'quantity',
                        'unit_cost',
                        // The bounded-un-dilution trio (gate round 1, C-1/I-5):
                        // the ceiling captured at draft time, and the audit pair
                        // recording what was and was not capitalized back.
                        'unit_cost_ceiling',
                        'wac_undilution_applied',
                        'wac_undilution_forgone',
                        'location_id',
                        'movement_id',
                        'cost_adjustment_movement_id',
                        'created_at',
                        'updated_at',
                    ]),
                    "supplier_goods_return_note_lines must carry the full line shape {$context}",
                );
            },
            function (string $context): void {
                $this->assertFalse(
                    Schema::hasTable('supplier_goods_return_note_lines'),
                    "supplier_goods_return_note_lines must be gone {$context}",
                );
                $this->assertFalse(
                    Schema::hasTable('supplier_goods_return_notes'),
                    "supplier_goods_return_notes must be gone {$context}",
                );
            },
        );
    }

    /**
     * Unattended-safety: `tenants:migrate` re-running a partially applied batch
     * must no-op on already-created tables, not throw "relation already exists".
     * The baseline migrate has already applied it, so calling `up()` again here
     * is exactly that re-run.
     */
    public function test_migration_up_is_a_no_op_when_the_tables_already_exist(): void
    {
        [$migration] = $this->requireTenantMigrations(self::MIGRATION);

        $migration->up();

        $this->assertTrue(Schema::hasTable('supplier_goods_return_notes'));
        $this->assertTrue(Schema::hasTable('supplier_goods_return_note_lines'));
    }

    /**
     * Gate round 1, M-4. The partial unique indexes are the stated hard backstop
     * for `createDraft`'s one-note-per-credit-note idempotency. They are created
     * OUTSIDE the `hasTable` guards with IF NOT EXISTS, so a run that died between
     * `Schema::create` and the index statements self-heals on the next
     * `tenants:migrate` instead of leaving the table permanently unguarded.
     * Dropping them and re-running `up()` is exactly that partial-failure shape.
     */
    public function test_re_running_up_restores_a_dropped_partial_unique_index(): void
    {
        $this->assertTrue($this->indexExists('supplier_goods_return_notes_credit_note_unique'));

        DB::statement('DROP INDEX IF EXISTS supplier_goods_return_notes_credit_note_unique');
        $this->assertFalse($this->indexExists('supplier_goods_return_notes_credit_note_unique'));

        [$migration] = $this->requireTenantMigrations(self::MIGRATION);
        $migration->up();

        $this->assertTrue(
            $this->indexExists('supplier_goods_return_notes_credit_note_unique'),
            'A re-run of tenants:migrate must re-assert the one-note-per-credit-note backstop.',
        );
    }

    private function indexExists(string $name): bool
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return DB::table('pg_indexes')->where('indexname', $name)->exists();
        }

        return DB::table('sqlite_master')
            ->where('type', 'index')
            ->where('name', $name)
            ->exists();
    }
}
