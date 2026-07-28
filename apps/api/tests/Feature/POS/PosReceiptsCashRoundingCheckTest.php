<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

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
 * Cash-rounding Phase 1 / migration B.
 *
 * MUST run on Postgres to mean anything: `pos_receipts_totals` is a
 * pgsql-only CHECK constraint (added by `2026_01_08_190637`, re-defined by
 * `2026_03_09_200000` behind the same driver guard) and is completely
 * invisible on SQLite. A green SQLite run proves nothing about the swap.
 *
 * The class deliberately does NOT skip wholesale in setUp(): the column,
 * index and model assertions are driver-agnostic and also pin that the
 * SQLite fast loop receives the new columns and partial indexes. Only the
 * CHECK-constraint assertions call `requirePostgres()`.
 *
 * Run with:
 *   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 \
 *     DB_DATABASE=autoerp_cash_rounding_test DB_CENTRAL_DATABASE=autoerp_cash_rounding_test \
 *     DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
 *     ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/POS/PosReceiptsCashRoundingCheckTest.php
 */
final class PosReceiptsCashRoundingCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $cashierId;

    private int $sequence = 1;

    /**
     * Skip the assertions that can only exist on Postgres. NOT done in
     * setUp(): the column/index/model assertions below are driver-agnostic
     * and must also prove the SQLite fast loop gets the new columns, which a
     * blanket class-level skip would hide.
     */
    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pos_receipts_totals is a pgsql-only CHECK constraint.');
        }
    }

    public function test_columns_exist_on_every_driver(): void
    {
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'cash_rounding_adjustment'));
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'cash_rounding_denomination'));
    }

    public function test_journal_partial_unique_indexes_exist_on_every_driver(): void
    {
        $names = array_map(
            static fn (array $index): string => (string) ($index['name'] ?? ''),
            Schema::getIndexes('journal_entries'),
        );

        $this->assertContains('uniq_je_source_pos_cash_rounding', $names);
        $this->assertContains('uniq_je_source_pos_tolerance_bridge', $names);
    }

    public function test_columns_exist_with_the_pinned_precision(): void
    {
        $this->requirePostgres();

        $this->assertTrue(Schema::hasColumn('pos_receipts', 'cash_rounding_adjustment'));
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'cash_rounding_denomination'));

        // Review-critical: the adjustment is decimal(12,3) — sibling-consistent
        // with `change_due`/`subtotal`/`tax_amount`/`discount_amount`/`total`,
        // all numeric(12,3) — NOT the 15,3 of `tolerance_writeoff`. The
        // denomination mirrors country_payment_settings' numeric(15,4).
        $this->assertSame(['12', '3'], $this->numericType('cash_rounding_adjustment'));
        $this->assertSame(['15', '4'], $this->numericType('cash_rounding_denomination'));

        // Both must be nullable: v1/v2 rows carry no rounding data at all.
        $this->assertSame('YES', $this->isNullable('cash_rounding_adjustment'));
        $this->assertSame('YES', $this->isNullable('cash_rounding_denomination'));
    }

    public function test_totals_check_now_includes_the_coalesced_adjustment(): void
    {
        $this->requirePostgres();

        $definition = $this->totalsConstraintDefinition();

        $this->assertNotNull($definition);
        $this->assertStringContainsString('cash_rounding_adjustment', $definition);
        $this->assertStringContainsString('COALESCE', strtoupper($definition));
    }

    public function test_legacy_null_adjustment_rows_still_enforce_the_identity(): void
    {
        $this->requirePostgres();

        // COALESCE is load-bearing: without it the CHECK evaluates to NULL on
        // a legacy row and PostgreSQL treats that as SATISFIED, silently
        // switching the totals identity off for all pre-v3 data.
        $this->assertViolatesTotalsCheck([
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '11.000', // wrong on purpose
            'cash_rounding_adjustment' => null,
        ]);
    }

    public function test_legacy_null_adjustment_row_with_a_correct_identity_is_still_accepted(): void
    {
        $this->requirePostgres();

        // Positive control for the COALESCE branch: an untouched v1/v2 row
        // must remain insertable after the swap.
        DB::table('pos_receipts')->insert($this->receiptRow([
            'subtotal' => '10.000',
            'tax_amount' => '1.900',
            'discount_amount' => '0.500',
            'total' => '11.400',
            'cash_rounding_adjustment' => null,
            'cash_rounding_denomination' => null,
        ]));

        $this->assertSame(1, DB::table('pos_receipts')->count());
    }

    public function test_rounded_row_with_nonzero_adjustment_is_accepted(): void
    {
        $this->requirePostgres();

        DB::table('pos_receipts')->insert($this->receiptRow([
            'subtotal' => '9.973',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '9.950',
            'cash_rounding_adjustment' => '-0.023',
            'cash_rounding_denomination' => '0.0500',
        ]));

        $this->assertSame(1, DB::table('pos_receipts')->count());
    }

    public function test_rounded_row_whose_total_ignores_the_adjustment_is_rejected(): void
    {
        $this->requirePostgres();

        $this->assertViolatesTotalsCheck([
            'subtotal' => '9.973',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '9.973',
            'cash_rounding_adjustment' => '-0.023',
            'cash_rounding_denomination' => '0.0500',
        ]);
    }

    public function test_positive_rounding_up_adjustment_is_accepted(): void
    {
        $this->requirePostgres();

        // Rounding UP (device rounds 9.930 → 9.950) yields a POSITIVE
        // adjustment; the column and the CHECK must accept both signs.
        DB::table('pos_receipts')->insert($this->receiptRow([
            'subtotal' => '9.930',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '9.950',
            'cash_rounding_adjustment' => '0.020',
            'cash_rounding_denomination' => '0.0500',
        ]));

        $this->assertSame(1, DB::table('pos_receipts')->count());
    }

    public function test_journal_partial_unique_indexes_exist_for_the_new_source_types_only(): void
    {
        $this->requirePostgres();

        $definitions = $this->journalIndexDefinitions();

        $this->assertArrayHasKey('uniq_je_source_pos_cash_rounding', $definitions);
        $this->assertArrayHasKey('uniq_je_source_pos_tolerance_bridge', $definitions);

        $rounding = $definitions['uniq_je_source_pos_cash_rounding'];
        $this->assertStringContainsString('UNIQUE INDEX', $rounding);
        $this->assertStringContainsString('source_type, source_id', $rounding);
        $this->assertStringContainsString('pos_cash_rounding', $rounding);
        $this->assertStringContainsString('pos_cash_rounding_refund', $rounding);

        $bridge = $definitions['uniq_je_source_pos_tolerance_bridge'];
        $this->assertStringContainsString('UNIQUE INDEX', $bridge);
        $this->assertStringContainsString('source_type, source_id', $bridge);
        $this->assertStringContainsString('pos_tolerance_bridge', $bridge);
    }

    public function test_no_partial_unique_index_covers_the_legacy_pos_payment_tolerance_source(): void
    {
        $this->requirePostgres();

        // `pos_payment_tolerance` is already populated by the legacy v2
        // write-off path and is NOT single-entry-per-source. Putting it under
        // a unique index would 23505 on live data.
        foreach ($this->journalIndexDefinitions() as $name => $definition) {
            $this->assertStringNotContainsString(
                "'pos_payment_tolerance'",
                $definition,
                "Index {$name} must not constrain the legacy pos_payment_tolerance source type",
            );
        }
    }

    public function test_migration_up_is_idempotent_on_rerun(): void
    {
        // tenants:migrate runs on every auto-deploy; a partially applied or
        // re-run migration must be a no-op, not a hard failure.
        $migration = require database_path(
            'migrations/tenant/2026_07_28_100200_add_cash_rounding_to_pos_receipts.php'
        );

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('pos_receipts', 'cash_rounding_adjustment'));
        $this->assertTrue(Schema::hasColumn('pos_receipts', 'cash_rounding_denomination'));

        $indexNames = array_map(
            static fn (array $index): string => (string) ($index['name'] ?? ''),
            Schema::getIndexes('journal_entries'),
        );
        $this->assertContains('uniq_je_source_pos_cash_rounding', $indexNames);
        $this->assertContains('uniq_je_source_pos_tolerance_bridge', $indexNames);

        // Everything below is the pgsql-only half of the re-run.
        $this->requirePostgres();

        $this->assertSame(['12', '3'], $this->numericType('cash_rounding_adjustment'));

        $definition = $this->totalsConstraintDefinition();
        $this->assertNotNull($definition);
        $this->assertStringContainsString('cash_rounding_adjustment', $definition);
        $this->assertStringContainsString('COALESCE', strtoupper($definition));

        // And the swapped constraint is still ENFORCING after the re-run.
        $this->assertViolatesTotalsCheck([
            'subtotal' => '9.973',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '9.973',
            'cash_rounding_adjustment' => '-0.023',
        ]);
    }

    public function test_migration_down_restores_the_legacy_identity_and_is_guarded(): void
    {
        $migration = require database_path(
            'migrations/tenant/2026_07_28_100200_add_cash_rounding_to_pos_receipts.php'
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            // THE regression case: a rounded receipt (adj <> 0) violates the
            // LEGACY identity by construction. A plain ADD CONSTRAINT in
            // down() validates against existing rows and aborts with
            // check_violation right here. The NOT VALID re-add must survive it.
            DB::table('pos_receipts')->insert($this->receiptRow([
                'subtotal' => '9.973',
                'tax_amount' => '0.000',
                'discount_amount' => '0.000',
                'total' => '9.950',
                'cash_rounding_adjustment' => '-0.023',
                'cash_rounding_denomination' => '0.0500',
            ]));
        }

        $migration->down();

        $this->assertFalse(Schema::hasColumn('pos_receipts', 'cash_rounding_adjustment'));
        $this->assertFalse(Schema::hasColumn('pos_receipts', 'cash_rounding_denomination'));

        $indexNames = array_map(
            static fn (array $index): string => (string) ($index['name'] ?? ''),
            Schema::getIndexes('journal_entries'),
        );
        $this->assertNotContains('uniq_je_source_pos_cash_rounding', $indexNames);
        $this->assertNotContains('uniq_je_source_pos_tolerance_bridge', $indexNames);

        // Guarded: a second down() must not fatal on the already-dropped
        // columns/indexes.
        $migration->down();

        $this->requirePostgres();

        $definition = $this->totalsConstraintDefinition();
        $this->assertNotNull($definition);
        $this->assertStringNotContainsString('cash_rounding_adjustment', $definition);
        $this->assertStringNotContainsString('COALESCE', strtoupper($definition));

        // Re-added NOT VALID: pg_get_constraintdef spells it out, and
        // pg_constraint.convalidated is false — that is what let the rollback
        // survive the pre-existing rounded row above.
        $this->assertStringContainsString('NOT VALID', $definition);
        $convalidated = DB::selectOne(
            "SELECT convalidated FROM pg_constraint WHERE conname = 'pos_receipts_totals'"
        );
        $this->assertNotNull($convalidated);
        $this->assertFalse((bool) $convalidated->convalidated);

        // The rounded row survived the rollback — fiscal immutability forbids
        // deleting it, which is exactly why a validating re-add is impossible.
        $this->assertSame(1, DB::table('pos_receipts')->count());

        // NOT VALID still enforces the restored identity on NEW writes: only
        // the historical scan is skipped.
        $this->assertViolatesTotalsCheck([
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '11.000',
        ]);
    }

    public function test_reapplying_up_after_a_rollback_survives_the_lost_adjustment_residue(): void
    {
        $this->requirePostgres();

        $migration = require database_path(
            'migrations/tenant/2026_07_28_100200_add_cash_rounding_to_pos_receipts.php'
        );

        // A rounded receipt, then a rollback: down() DROPS the adjustment
        // column, so the row survives as bare `total 9.950 / subtotal 9.973`
        // residue that satisfies NEITHER identity.
        DB::table('pos_receipts')->insert($this->receiptRow([
            'subtotal' => '9.973',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '9.950',
            'cash_rounding_adjustment' => '-0.023',
            'cash_rounding_denomination' => '0.0500',
        ]));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('pos_receipts', 'cash_rounding_adjustment'));
        $this->assertSame(1, DB::table('pos_receipts')->count());

        // Re-apply. A validating ADD CONSTRAINT would 23514 here and hard-fail
        // tenants:migrate for this tenant; the savepointed VALIDATE must let
        // up() succeed instead.
        $migration->up();

        $this->assertTrue(Schema::hasColumn('pos_receipts', 'cash_rounding_adjustment'));
        $this->assertNull(
            DB::table('pos_receipts')->value('cash_rounding_adjustment'),
            'down() destroyed the adjustment values — the re-added column is all-NULL',
        );

        $definition = $this->totalsConstraintDefinition();
        $this->assertNotNull($definition);
        $this->assertStringContainsString('cash_rounding_adjustment', $definition);
        $this->assertStringContainsString('COALESCE', strtoupper($definition));

        // Left NOT VALID: the residue row could not be validated.
        $convalidated = DB::selectOne(
            "SELECT convalidated FROM pg_constraint WHERE conname = 'pos_receipts_totals'"
        );
        $this->assertNotNull($convalidated);
        $this->assertFalse(
            (bool) $convalidated->convalidated,
            'Expected pos_receipts_totals to stay NOT VALID after re-apply over unvalidatable residue',
        );

        // But NEW writes are still fully enforced.
        $this->assertViolatesTotalsCheck([
            'subtotal' => '9.973',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '9.973',
            'cash_rounding_adjustment' => '-0.023',
        ]);
    }

    public function test_first_apply_leaves_the_constraint_fully_validated(): void
    {
        $this->requirePostgres();

        // The NOT VALID + VALIDATE pair must be a no-op difference on the
        // normal path: after a clean migrate the constraint is convalidated,
        // exactly as a plain validating ADD would have left it.
        $convalidated = DB::selectOne(
            "SELECT convalidated FROM pg_constraint WHERE conname = 'pos_receipts_totals'"
        );

        $this->assertNotNull($convalidated);
        $this->assertTrue((bool) $convalidated->convalidated);
        $this->assertStringNotContainsString('NOT VALID', (string) $this->totalsConstraintDefinition());
    }

    public function test_receipt_model_exposes_the_new_columns(): void
    {
        $receipt = new Receipt;

        $this->assertContains('cash_rounding_adjustment', $receipt->getFillable());
        $this->assertContains('cash_rounding_denomination', $receipt->getFillable());
        $this->assertSame('decimal:3', $receipt->getCasts()['cash_rounding_adjustment'] ?? null);
        $this->assertSame('decimal:4', $receipt->getCasts()['cash_rounding_denomination'] ?? null);
    }

    /**
     * Assert the insert fails specifically on `pos_receipts_totals` — not on
     * an FK, not on a sibling CHECK. A bare `expectException(QueryException)`
     * would go green for the wrong reason.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function assertViolatesTotalsCheck(array $overrides): void
    {
        try {
            DB::table('pos_receipts')->insert($this->receiptRow($overrides));
        } catch (QueryException $e) {
            $this->assertStringContainsString('pos_receipts_totals', $e->getMessage());

            return;
        }

        $this->fail('Expected the pos_receipts_totals CHECK to reject the row, but the insert succeeded.');
    }

    /**
     * @return array<string, string> index name => index definition
     */
    private function journalIndexDefinitions(): array
    {
        /** @var list<object{indexname: string, indexdef: string}> $rows */
        $rows = DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'journal_entries'");

        $definitions = [];
        foreach ($rows as $row) {
            $definitions[(string) $row->indexname] = (string) $row->indexdef;
        }

        return $definitions;
    }

    private function totalsConstraintDefinition(): ?string
    {
        $row = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'pos_receipts_totals'"
        );

        return $row === null ? null : (string) $row->def;
    }

    /**
     * @return array{0: string, 1: string} [precision, scale]
     */
    private function numericType(string $column): array
    {
        $row = DB::selectOne(
            'SELECT numeric_precision, numeric_scale FROM information_schema.columns '
                .'WHERE table_name = ? AND column_name = ?',
            ['pos_receipts', $column]
        );

        $this->assertNotNull($row, "Column pos_receipts.{$column} not found");

        return [(string) $row->numeric_precision, (string) $row->numeric_scale];
    }

    private function isNullable(string $column): string
    {
        $row = DB::selectOne(
            'SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['pos_receipts', $column]
        );

        $this->assertNotNull($row, "Column pos_receipts.{$column} not found");

        return (string) $row->is_nullable;
    }

    /**
     * Minimal `pos_receipts` row that satisfies every NOT NULL column, every
     * FK (company/location/terminal/cashier are all FK-constrained — the row
     * cannot use throwaway UUIDs) and every sibling CHECK, so the ONLY thing
     * a rejection can be attributed to is `pos_receipts_totals`.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function receiptRow(array $overrides): array
    {
        $this->ensureFkParents();

        return array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'terminal_id' => $this->terminalId,
            'receipt_number' => 'T001-C042-L01-POS03-2026-'.str_pad((string) $this->sequence, 8, '0', STR_PAD_LEFT),
            'receipt_type' => 'sale',
            'chain_sequence' => $this->sequence++,
            'receipt_year' => 2026,
            'fiscal_hash' => str_repeat('a', 64),
            'previous_hash' => str_repeat('0', 64),
            'vat_breakdown_hash' => str_repeat('b', 64),
            'payment_methods_hash' => str_repeat('c', 64),
            'posted_at' => now(),
            'cashier_id' => $this->cashierId,
            'cashier_name' => 'Check Test Cashier',
            'currency' => 'TND',
            'fiscal_status' => 'fiscalized',
            'invoice_type_code' => 'SALE',
            'training_flag' => false,
            'is_voided' => false,
            'is_training' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function ensureFkParents(): void
    {
        if (isset($this->tenantId)) {
            return;
        }

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $this->tenantId = (string) $tenant->id;
        $this->companyId = (string) $company->id;
        $this->locationId = (string) $location->id;
        $this->terminalId = (string) $terminal->id;
        $this->cashierId = (string) $cashier->id;
    }
}
