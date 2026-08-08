<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Domain\StockAdjustment;
use App\Modules\Inventory\Domain\StockAdjustmentLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DPA V7 / T9 — the `stock_adjustments` schema contract.
 *
 * Cloned from GoodsReceiptLedgerSchemaTest: full column lists, per-column type +
 * nullability, a migration source-grep for the decimal precisions, every index
 * definition, the status vocabulary and the model casts.
 *
 * The two CHECK predicates are asserted through `pg_get_constraintdef()` on
 * PostgreSQL and, on SQLite (where CHECKs are not created at all), through a grep
 * of the migration source for the FROZEN literals — frozen deliberately, per D6a:
 * `tenants:migrate` runs the file at each tenant's provisioning time, so a
 * derived predicate would fork the schema between tenant cohorts. The derivation
 * lives in tests/Unit/Inventory/AdjustmentReasonSignPartitionTest.php.
 */
final class StockAdjustmentLedgerSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/tenant/2026_08_08_120000_create_stock_adjustments_tables.php';

    public function test_stock_adjustment_tables_match_the_document_grain_contract(): void
    {
        $this->assertTrue(Schema::hasTable('stock_adjustments'));
        $this->assertTrue(Schema::hasTable('stock_adjustment_lines'));

        $this->assertTrue(Schema::hasColumns('stock_adjustments', [
            'id', 'tenant_id', 'company_id', 'adjustment_number', 'status', 'note',
            'location_id', 'occurred_at', 'idempotency_key',
            'created_by_user_id', 'posted_by_user_id', 'cancelled_by_user_id',
            'stale_acknowledged_by_user_id', 'reservations_ignored_by_user_id',
            'posted_at', 'cancelled_at', 'stale_acknowledged_at', 'reservations_ignored_at',
            'cancellation_reason', 'corrects_adjustment_id', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('stock_adjustment_lines', [
            'id', 'adjustment_id', 'tenant_id', 'company_id', 'product_id', 'variant_id',
            'batch_id', 'reason_code', 'delta_quantity', 'observed_before',
            'quantity_before', 'quantity_after', 'movement_id', 'line_note',
            'created_at', 'updated_at',
        ]));

        // Header nullability is the lifecycle contract: a draft has no number, no
        // poster, no override audit.
        $this->assertColumn('stock_adjustments', 'tenant_id', 'uuid', nullable: false);
        $this->assertColumn('stock_adjustments', 'adjustment_number', 'varchar', nullable: true);
        $this->assertColumn('stock_adjustments', 'status', 'varchar', nullable: false);
        $this->assertColumn('stock_adjustments', 'location_id', 'uuid', nullable: false);
        $this->assertColumn('stock_adjustments', 'occurred_at', 'timestamp', nullable: false);
        $this->assertColumn('stock_adjustments', 'idempotency_key', 'varchar', nullable: true);
        $this->assertColumn('stock_adjustments', 'created_by_user_id', 'uuid', nullable: false);
        $this->assertColumn('stock_adjustments', 'posted_by_user_id', 'uuid', nullable: true);
        $this->assertColumn('stock_adjustments', 'stale_acknowledged_at', 'timestamp', nullable: true);
        $this->assertColumn('stock_adjustments', 'reservations_ignored_at', 'timestamp', nullable: true);
        $this->assertColumn('stock_adjustments', 'corrects_adjustment_id', 'uuid', nullable: true);

        // delta_quantity and observed_before are NOT nullable: a line without them
        // is not authorable. quantity_before/after ARE, because they record what
        // was actually POSTED and a draft has posted nothing.
        $this->assertColumn('stock_adjustment_lines', 'reason_code', 'varchar', nullable: false);
        $this->assertColumn('stock_adjustment_lines', 'delta_quantity', 'numeric', nullable: false);
        $this->assertColumn('stock_adjustment_lines', 'observed_before', 'numeric', nullable: false);
        $this->assertColumn('stock_adjustment_lines', 'quantity_before', 'numeric', nullable: true);
        $this->assertColumn('stock_adjustment_lines', 'quantity_after', 'numeric', nullable: true);
        $this->assertColumn('stock_adjustment_lines', 'movement_id', 'uuid', nullable: true);
        $this->assertColumn('stock_adjustment_lines', 'variant_id', 'uuid', nullable: true);
    }

    public function test_quantity_columns_are_declared_at_the_canonical_scale(): void
    {
        $source = (string) file_get_contents(base_path(self::MIGRATION));

        foreach (['delta_quantity', 'observed_before', 'quantity_before', 'quantity_after'] as $column) {
            $this->assertMatchesRegularExpression(
                "/decimal\('{$column}', 15, 4\)/",
                $source,
                "{$column} must be decimal(15,4) — the canonical quantity scale (rule 19)."
            );
        }

        // batch_id is an INTEGER FK because product_batches is int-keyed; the HTTP
        // contract speaks the public uuid (D1b part 1).
        $this->assertStringContainsString("unsignedBigInteger('batch_id')", $source);
        $this->assertStringContainsString("string('adjustment_number', 30)", $source);
    }

    public function test_every_index_from_the_plan_exists(): void
    {
        foreach ([
            'stock_adjustments_company_status_idx' => false,
            'stock_adjustments_company_occurred_idx' => false,
            'stock_adjustments_company_location_idx' => false,
            'stock_adjustment_lines_product_idx' => false,
            'stock_adjustment_lines_adjustment_idx' => false,
            'stock_adjustment_lines_batch_idx' => false,
            'stock_adjustment_lines_sku_unique_nv_nb' => true,
            'stock_adjustment_lines_sku_unique_v_nb' => true,
            'stock_adjustment_lines_sku_unique_nv_b' => true,
            'stock_adjustment_lines_sku_unique_v_b' => true,
            'stock_adjustment_lines_movement_id_unique' => true,
            'stock_adjustments_company_number_unique' => true,
            'stock_adjustments_corrects_unique' => true,
            'stock_adjustments_idempotency_unique' => true,
        ] as $indexName => $unique) {
            $this->assertIndexExists($indexName, $unique);
        }
    }

    public function test_every_nullable_uniqueness_is_partial(): void
    {
        // Not cosmetic: a PLAIN unique over a nullable column relies on PG's
        // NULLS DISTINCT default and would change meaning under NULLS NOT
        // DISTINCT — which would make two DRAFTS (both number-less) collide.
        $this->assertIndexSqlContains('stock_adjustments_company_number_unique', 'WHERE adjustment_number IS NOT NULL');
        $this->assertIndexSqlContains('stock_adjustments_idempotency_unique', 'WHERE idempotency_key IS NOT NULL');
        $this->assertIndexSqlContains('stock_adjustments_corrects_unique', 'WHERE corrects_adjustment_id IS NOT NULL');
        $this->assertIndexSqlContains('stock_adjustment_lines_movement_id_unique', 'WHERE movement_id IS NOT NULL');
        $this->assertIndexSqlContains('stock_adjustment_lines_sku_unique_nv_nb', 'WHERE variant_id IS NULL AND batch_id IS NULL');
        $this->assertIndexSqlContains('stock_adjustment_lines_sku_unique_v_b', 'WHERE variant_id IS NOT NULL AND batch_id IS NOT NULL');
    }

    public function test_the_status_vocabulary_is_exactly_draft_posted_cancelled(): void
    {
        $this->assertSame(
            ['draft', 'posted', 'cancelled'],
            array_map(
                static fn (StockAdjustmentStatus $status): string => $status->value,
                StockAdjustmentStatus::cases(),
            ),
        );
    }

    public function test_the_models_cast_quantities_to_decimal_strings_and_the_status_to_its_enum(): void
    {
        $header = (new StockAdjustment)->getCasts();
        $this->assertSame(StockAdjustmentStatus::class, $header['status'] ?? null);
        $this->assertSame('datetime', $header['occurred_at'] ?? null);
        $this->assertSame('datetime', $header['stale_acknowledged_at'] ?? null);
        $this->assertSame('datetime', $header['reservations_ignored_at'] ?? null);

        $line = (new StockAdjustmentLine)->getCasts();
        $this->assertSame(MovementReason::class, $line['reason_code'] ?? null);
        foreach (['delta_quantity', 'observed_before', 'quantity_before', 'quantity_after'] as $column) {
            $this->assertSame('decimal:4', $line[$column] ?? null, "{$column} must be cast decimal:4.");
        }
        $this->assertSame('integer', $line['batch_id'] ?? null);
    }

    public function test_the_reason_sign_and_nonzero_checks_are_the_frozen_predicate(): void
    {
        $source = (string) file_get_contents(base_path(self::MIGRATION));

        // On SQLite the CHECKs are not created at all, so the migration source is
        // the only available evidence. On PostgreSQL the EFFECTIVE predicate is
        // read back from the catalogue.
        $this->assertStringContainsString("reason_code IN ('adjustment_positive') AND delta_quantity > 0", $source);
        $this->assertStringContainsString("reason_code IN ('adjustment_negative','damage','write_off') AND delta_quantity < 0", $source);
        $this->assertStringContainsString('CHECK (delta_quantity <> 0)', $source);
        // The frozen-ness is deliberate and must stay documented in the file.
        $this->assertStringContainsString('FROZEN LITERALS', $source);

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestIncomplete('CHECK constraints are only created on PostgreSQL; source-grepped above.');
        }

        $signDef = (string) DB::scalar(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'stock_adjustment_lines_reason_sign'"
        );
        $this->assertStringContainsString('adjustment_positive', $signDef);
        $this->assertStringContainsString('adjustment_negative', $signDef);
        $this->assertStringContainsString('damage', $signDef);
        $this->assertStringContainsString('write_off', $signDef);
        $this->assertDoesNotMatchRegularExpression('/opening_balance|expiry|consumption|count_correction/', $signDef);

        $nonZeroDef = (string) DB::scalar(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'stock_adjustment_lines_delta_nonzero'"
        );
        $this->assertStringContainsString('<> (0)', str_replace(' ', ' ', $nonZeroDef));
    }

    // ------------------------------------------------------------- helpers

    private function assertColumn(string $table, string $column, string $expectedType, bool $nullable): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $info = DB::selectOne(
                'SELECT data_type, is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                [$table, $column],
            );

            $this->assertNotNull($info, "Column {$table}.{$column} is missing.");
            $this->assertStringContainsStringIgnoringCase(
                $this->pgTypeAlias($expectedType),
                (string) $info->data_type,
            );
            $this->assertSame(
                $nullable ? 'YES' : 'NO',
                (string) $info->is_nullable,
                "Unexpected nullability for {$table}.{$column}.",
            );

            return;
        }

        $info = null;
        foreach (DB::select("PRAGMA table_info('{$table}')") as $candidate) {
            if ($candidate->name === $column) {
                $info = $candidate;
                break;
            }
        }

        $this->assertNotNull($info, "Column {$table}.{$column} is missing.");
        $this->assertStringContainsStringIgnoringCase($this->sqliteTypeAlias($expectedType), (string) $info->type);
        $this->assertSame($nullable ? 0 : 1, (int) $info->notnull, "Unexpected nullability for {$table}.{$column}.");
    }

    private function pgTypeAlias(string $expectedType): string
    {
        return match ($expectedType) {
            'timestamp' => 'timestamp',
            'varchar' => 'character varying',
            default => $expectedType,
        };
    }

    private function sqliteTypeAlias(string $expectedType): string
    {
        return match ($expectedType) {
            'timestamp' => 'datetime',
            'uuid' => 'varchar',
            default => $expectedType,
        };
    }

    private function assertIndexExists(string $indexName, bool $unique): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $index = DB::selectOne(
                'SELECT indexname, indexdef FROM pg_indexes WHERE indexname = ?',
                [$indexName],
            );
            $this->assertNotNull($index, "Index {$indexName} is missing.");
            $this->assertSame(
                $unique,
                str_contains((string) $index->indexdef, 'UNIQUE INDEX'),
                "Unexpected uniqueness for {$indexName}.",
            );

            return;
        }

        $index = DB::selectOne(
            'SELECT name, [unique] FROM pragma_index_list(?) WHERE name = ?',
            ['stock_adjustments', $indexName],
        ) ?? DB::selectOne(
            'SELECT name, [unique] FROM pragma_index_list(?) WHERE name = ?',
            ['stock_adjustment_lines', $indexName],
        );

        $this->assertNotNull($index, "Index {$indexName} is missing.");
        $this->assertSame($unique ? 1 : 0, (int) $index->unique, "Unexpected uniqueness for {$indexName}.");
    }

    private function stripParens(string $sql): string
    {
        return str_replace(['(', ')'], '', $sql);
    }

    private function assertIndexSqlContains(string $indexName, string $expectedSql): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $index = DB::selectOne('SELECT indexdef FROM pg_indexes WHERE indexname = ?', [$indexName]);
            $this->assertNotNull($index, "Index {$indexName} is missing.");
            // PostgreSQL re-renders the predicate with its own parenthesisation
            // ("WHERE (adjustment_number IS NOT NULL)"), so compare on the
            // paren-stripped form rather than pinning one driver's formatting.
            $this->assertStringContainsString(
                $this->stripParens($expectedSql),
                $this->stripParens((string) $index->indexdef),
            );

            return;
        }

        $index = DB::selectOne(
            'SELECT sql FROM sqlite_master WHERE type = ? AND name = ?',
            ['index', $indexName],
        );

        $this->assertNotNull($index, "Index {$indexName} is missing from sqlite_master.");
        $this->assertStringContainsString($expectedSql, (string) $index->sql);
    }
}
