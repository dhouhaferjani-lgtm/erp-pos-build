<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\OrderLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies that pos_orders and pos_order_lines monetary columns were narrowed
 * from decimal(15,4) to decimal(15,3), matching the fiscal receipt scale.
 *
 * Schema assertions are PostgreSQL-only (information_schema.columns is PG-specific);
 * the round-trip test runs on both SQLite and PostgreSQL and asserts the decimal:3
 * Eloquent cast serialises as 3 decimal places.
 */
final class PosOrderScale3Test extends TestCase
{
    use RefreshDatabase;

    // ─── Schema shape (PostgreSQL only) ──────────────────────────────────────

    public function test_pos_orders_monetary_columns_are_decimal_15_3(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $cols = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name = 'pos_orders'
              AND column_name IN ('subtotal','tax_amount','discount_amount','total')
        ");

        $this->assertCount(4, $cols, 'expected 4 monetary columns on pos_orders');
        foreach ($cols as $c) {
            $this->assertSame(15, (int) $c->numeric_precision, "{$c->column_name} precision");
            $this->assertSame(3, (int) $c->numeric_scale, "{$c->column_name} scale");
        }
    }

    public function test_pos_order_lines_monetary_columns_are_decimal_15_3(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $cols = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name = 'pos_order_lines'
              AND column_name IN ('unit_price','discount_amount','tax_amount','line_total')
        ");

        $this->assertCount(4, $cols, 'expected 4 monetary columns on pos_order_lines');
        foreach ($cols as $c) {
            $this->assertSame(15, (int) $c->numeric_precision, "{$c->column_name} precision");
            $this->assertSame(3, (int) $c->numeric_scale, "{$c->column_name} scale");
        }
    }

    // ─── Round-trip (SQLite + PostgreSQL) ────────────────────────────────────

    public function test_order_stores_3_decimal_monetary_values_roundtrip(): void
    {
        ['order' => $order] = $this->createOrderWithLine();

        $freshOrder = $order->fresh();
        $this->assertNotNull($freshOrder);

        // Monetary totals must serialize with exactly 3 decimal places
        $this->assertSame('5.000', $freshOrder->subtotal);
        $this->assertSame('0.000', $freshOrder->discount_amount);
    }

    public function test_order_line_stores_3_decimal_monetary_values_roundtrip(): void
    {
        ['line' => $line] = $this->createOrderWithLine();

        $freshLine = $line->fresh();
        $this->assertNotNull($freshLine);

        // Monetary line fields must serialize with exactly 3 decimal places
        $this->assertSame('5.000', $freshLine->unit_price);
        $this->assertSame('0.000', $freshLine->discount_amount);
        $this->assertSame('5.000', $freshLine->line_total);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array{order: Order, line: OrderLine}
     */
    private function createOrderWithLine(): array
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
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sale_price' => '5.000',
            'tax_rate' => '19.00',
        ]);

        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => 'OPEN',
            'opened_at' => now(),
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'order_number' => '#001',
            'status' => OrderStatus::Open,
            'cashier_id' => $user->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => '5.000',
            'tax_amount' => '0.800',
            'discount_amount' => '0.000',
            'total' => '5.800',
            'currency' => 'TND',
            'opened_at' => now(),
        ]);

        $line = OrderLine::create([
            'order_id' => $order->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_name' => 'Espresso',
            'quantity' => '1.000',
            'unit_price' => '5.000',
            'discount_amount' => '0.000',
            'tax_rate' => '19.00',
            'tax_amount' => '0.800',
            'line_total' => '5.000',
            'status' => OrderLineStatus::Pending,
        ]);

        return ['order' => $order, 'line' => $line];
    }
}
