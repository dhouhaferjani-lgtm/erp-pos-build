<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the canonical 4-decimal-place quantity rule across all non-inventory
 * modules widened by 2026_05_29_100002_widen_quantity_columns_to_scale_4.
 *
 * CANONICAL QUANTITY RULE: every quantity column in the ERP uses decimal(15,4).
 *
 * Schema-shape tests require PostgreSQL (information_schema.columns is
 * Postgres-specific) and are skipped on SQLite.
 *
 * Round-trip tests run on all drivers — they insert a value and assert it
 * is returned by the model with exactly 4 decimal places via the decimal:4
 * Eloquent cast.
 */
final class QuantityColumnScale4Test extends TestCase
{
    use RefreshDatabase;

    // ─── Schema shape (PostgreSQL only) ──────────────────────────────────────

    public function test_catalog_cart_items_quantity_is_decimal_15_4(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $cols = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name  = 'catalog_cart_items'
              AND column_name = 'quantity'
        ");

        $this->assertCount(1, $cols, 'catalog_cart_items.quantity column not found');
        $this->assertSame(15, (int) $cols[0]->numeric_precision, 'precision should be 15');
        $this->assertSame(4, (int) $cols[0]->numeric_scale, 'scale should be 4');
    }

    public function test_pos_receipt_lines_quantity_is_decimal_15_4(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $cols = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name  = 'pos_receipt_lines'
              AND column_name = 'quantity'
        ");

        $this->assertCount(1, $cols, 'pos_receipt_lines.quantity column not found');
        $this->assertSame(15, (int) $cols[0]->numeric_precision, 'precision should be 15');
        $this->assertSame(4, (int) $cols[0]->numeric_scale, 'scale should be 4');
    }

    public function test_pos_order_lines_quantity_is_decimal_15_4(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $cols = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name  = 'pos_order_lines'
              AND column_name = 'quantity'
        ");

        $this->assertCount(1, $cols, 'pos_order_lines.quantity column not found');
        $this->assertSame(15, (int) $cols[0]->numeric_precision, 'precision should be 15');
        $this->assertSame(4, (int) $cols[0]->numeric_scale, 'scale should be 4');
    }

    public function test_workshop_work_order_lines_quantity_is_decimal_15_4(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $cols = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name  = 'workshop_work_order_lines'
              AND column_name = 'quantity'
        ");

        $this->assertCount(1, $cols, 'workshop_work_order_lines.quantity column not found');
        $this->assertSame(15, (int) $cols[0]->numeric_precision, 'precision should be 15');
        $this->assertSame(4, (int) $cols[0]->numeric_scale, 'scale should be 4');
    }

    public function test_marketplace_listings_quantity_columns_are_decimal_15_4(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $cols = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name  = 'marketplace_listings'
              AND column_name IN ('quantity_available', 'min_order_quantity')
            ORDER BY column_name
        ");

        $this->assertCount(2, $cols, 'expected 2 quantity columns on marketplace_listings');
        foreach ($cols as $c) {
            $this->assertSame(15, (int) $c->numeric_precision, "{$c->column_name} precision should be 15");
            $this->assertSame(4, (int) $c->numeric_scale, "{$c->column_name} scale should be 4");
        }
    }

    // ─── Round-trip (SQLite + PostgreSQL) ────────────────────────────────────

    /**
     * POS ReceiptLine: a 4-decimal quantity must round-trip via decimal:4 cast.
     */
    public function test_pos_receipt_line_quantity_roundtrip_preserves_4_decimals(): void
    {
        ['receipt' => $receipt] = $this->createReceiptFixture();

        $line = ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_code' => 'ITEM-001',
            'product_name' => 'Test Item',
            'quantity' => '5.1234',
            'unit' => 'kg',
            'unit_price' => '10.000',
            'line_total' => '51.234',
            'tax_rate' => '19.00',
            'tax_amount' => '8.200',
            'discount_amount' => '0.000',
        ]);

        $fresh = $line->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('5.1234', $fresh->quantity, 'ReceiptLine.quantity must round-trip at 4 dp');
    }

    /**
     * Cart CatalogCartItem: a 4-decimal quantity must round-trip via decimal:4 cast.
     */
    public function test_catalog_cart_item_quantity_roundtrip_preserves_4_decimals(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $cart = CatalogCart::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $item = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'source' => 'manual',
            'article_name' => 'Test Article',
            'quantity' => '3.7500',
            'unit_price' => '10.000',
            'currency' => 'TND',
            'sort_order' => 0,
        ]);

        $fresh = $item->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('3.7500', $fresh->quantity, 'CatalogCartItem.quantity must round-trip at 4 dp');
    }

    /**
     * Workshop WorkOrderLine: a 4-decimal quantity must round-trip via decimal:4 cast.
     */
    public function test_workshop_work_order_line_quantity_roundtrip_preserves_4_decimals(): void
    {
        $wo = WorkOrder::factory()->create(['currency' => 'TND']);

        $line = WorkOrderLine::factory()->create([
            'work_order_id' => $wo->id,
            'tenant_id' => $wo->tenant_id,
            'line_type' => WorkOrderLineType::Part,
            'quantity' => '2.1250',
        ]);

        $fresh = $line->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('2.1250', $fresh->quantity, 'WorkOrderLine.quantity must round-trip at 4 dp');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array{receipt: Receipt}
     */
    private function createReceiptFixture(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => 'OPEN',
            'opened_at' => now(),
        ]);

        $receipt = Receipt::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'receipt_type' => ReceiptType::Sale,
            'subtotal' => '51.234',
            'tax_amount' => '8.200',
            'total' => '59.434',
            'currency' => 'TND',
        ]);

        return ['receipt' => $receipt];
    }
}
