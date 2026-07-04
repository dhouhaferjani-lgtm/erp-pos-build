<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoodsReceiptLedgerSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function goods_receipt_ledger_tables_match_the_receipt_grain_contract(): void
    {
        $this->assertLessThanOrEqual(20, strlen('goods_receipt'));

        $this->assertTrue(Schema::hasTable('goods_receipts'));
        $this->assertTrue(Schema::hasColumns('goods_receipts', [
            'id',
            'tenant_id',
            'company_id',
            'purchase_order_id',
            'receipt_number',
            'status',
            'received_at',
            'received_by',
            'notes',
            'payload',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('goods_receipt_lines'));
        $this->assertTrue(Schema::hasColumns('goods_receipt_lines', [
            'id',
            'tenant_id',
            'company_id',
            'goods_receipt_id',
            'po_line_id',
            'product_id',
            'variant_id',
            'received_qty',
            'free_qty',
            'received_unit_price',
            'landed_unit_cost',
            'accrual_unit_cost',
            'effective_unit_cost',
            'movement_id',
            'free_movement_id',
            'quantity_invoiced',
            'price_override_by',
            'price_override_at',
            'price_override_old_basis',
            'price_override_reason',
            'created_at',
            'updated_at',
        ]));

        $this->assertColumn('goods_receipts', 'receipt_number', 'varchar', false);
        $this->assertColumn('goods_receipts', 'status', 'varchar', false);
        $this->assertColumn('goods_receipts', 'purchase_order_id', 'varchar', false);
        $this->assertColumn('goods_receipts', 'received_by', 'varchar', true);
        $this->assertColumn('goods_receipts', 'payload', 'text', true);

        $this->assertColumn('goods_receipt_lines', 'received_qty', 'numeric', false);
        $this->assertColumn('goods_receipt_lines', 'free_qty', 'numeric', false);
        $this->assertColumn('goods_receipt_lines', 'received_unit_price', 'numeric', true);
        $this->assertColumn('goods_receipt_lines', 'landed_unit_cost', 'numeric', false);
        $this->assertColumn('goods_receipt_lines', 'accrual_unit_cost', 'numeric', false);
        $this->assertColumn('goods_receipt_lines', 'effective_unit_cost', 'numeric', false);
        $this->assertColumn('goods_receipt_lines', 'quantity_invoiced', 'numeric', false);
        $this->assertColumn('goods_receipt_lines', 'price_override_old_basis', 'numeric', true);

        $migration = file_get_contents(database_path('migrations/tenant/2026_07_04_100000_create_goods_receipts_tables.php'));
        $this->assertIsString($migration);
        $this->assertStringContainsString("decimal('received_qty', 15, 4)", $migration);
        $this->assertStringContainsString("decimal('free_qty', 15, 4)", $migration);
        $this->assertStringContainsString("decimal('received_unit_price', 15, 3)", $migration);
        $this->assertStringContainsString("decimal('landed_unit_cost', 19, 6)", $migration);
        $this->assertStringContainsString("decimal('accrual_unit_cost', 15, 6)", $migration);
        $this->assertStringContainsString("decimal('effective_unit_cost', 19, 6)", $migration);
        $this->assertStringContainsString("decimal('quantity_invoiced', 15, 4)", $migration);
        $this->assertStringContainsString("decimal('price_override_old_basis', 15, 6)", $migration);
        $this->assertStringContainsString('restrictOnDelete()', $migration);
        $this->assertStringContainsString('goods_receipt_lines_movement_id_unique', $migration);
        $this->assertStringContainsString('goods_receipt_lines_free_movement_id_unique', $migration);
        $this->assertStringContainsString('WHERE movement_id IS NOT NULL', $migration);
        $this->assertStringContainsString('WHERE free_movement_id IS NOT NULL', $migration);

        $this->assertSame(['draft', 'posted', 'cancelled'], array_map(
            static fn (GoodsReceiptStatus $status): string => $status->value,
            GoodsReceiptStatus::cases(),
        ));

        $this->assertSame(GoodsReceiptStatus::class, (new GoodsReceipt)->getCasts()['status']);
        $this->assertSame('array', (new GoodsReceipt)->getCasts()['payload']);
        $this->assertSame('decimal:4', (new GoodsReceiptLine)->getCasts()['received_qty']);
        $this->assertSame('decimal:4', (new GoodsReceiptLine)->getCasts()['free_qty']);
        $this->assertSame('decimal:3', (new GoodsReceiptLine)->getCasts()['received_unit_price']);
        $this->assertSame('decimal:6', (new GoodsReceiptLine)->getCasts()['landed_unit_cost']);
        $this->assertSame('decimal:6', (new GoodsReceiptLine)->getCasts()['accrual_unit_cost']);
        $this->assertSame('decimal:6', (new GoodsReceiptLine)->getCasts()['effective_unit_cost']);
        $this->assertSame('decimal:4', (new GoodsReceiptLine)->getCasts()['quantity_invoiced']);

        $this->assertIndexExists('goods_receipts_company_id_receipt_number_unique', true);
        $this->assertIndexExists('goods_receipts_tenant_id_index', false);
        $this->assertIndexExists('goods_receipt_lines_tenant_id_po_line_id_index', false);
        $this->assertIndexExists('goods_receipt_lines_tenant_id_goods_receipt_id_index', false);
        $this->assertIndexExists('goods_receipt_lines_movement_id_unique', true);
        $this->assertIndexExists('goods_receipt_lines_free_movement_id_unique', true);
    }

    private function assertColumn(
        string $table,
        string $column,
        string $expectedType,
        bool $nullable,
    ): void {
        $columnInfo = $this->columnInfo($table, $column);

        $this->assertNotNull($columnInfo, "Column {$table}.{$column} is missing.");
        $this->assertStringContainsStringIgnoringCase($expectedType, (string) $columnInfo->type);
        $this->assertSame($nullable ? 0 : 1, (int) $columnInfo->notnull, "Unexpected nullability for {$table}.{$column}.");
    }

    private function columnInfo(string $table, string $column): ?object
    {
        $columns = DB::select("PRAGMA table_info('{$table}')");

        foreach ($columns as $columnInfo) {
            if ($columnInfo->name === $column) {
                return $columnInfo;
            }
        }

        return null;
    }

    private function assertIndexExists(string $indexName, bool $unique): void
    {
        $index = DB::selectOne(
            'SELECT name, [unique] FROM pragma_index_list(?) WHERE name = ?',
            ['goods_receipts', $indexName],
        ) ?? DB::selectOne(
            'SELECT name, [unique] FROM pragma_index_list(?) WHERE name = ?',
            ['goods_receipt_lines', $indexName],
        );

        $this->assertNotNull($index, "Index {$indexName} is missing.");
        $this->assertSame($unique ? 1 : 0, (int) $index->unique, "Unexpected uniqueness for {$indexName}.");
    }
}
