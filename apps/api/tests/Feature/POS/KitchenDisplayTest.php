<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Enums\TableStatus;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\OrderLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Table;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class KitchenDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    public function test_kitchen_orders_returns_sent_and_ready_orders(): void
    {
        $this->createOrderWithStatus(OrderStatus::SentToKitchen);
        $this->createOrderWithStatus(OrderStatus::Ready);
        $this->createOrderWithStatus(OrderStatus::Open);
        $this->createOrderWithStatus(OrderStatus::Closed);

        $response = $this->getJson('/api/v1/pos/kitchen/orders');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_update_line_status_sent_to_preparing(): void
    {
        $order = $this->createOrderSentToKitchen();
        $line = $order->lines->first();

        $response = $this->patchJson(
            "/api/v1/pos/kitchen/orders/{$order->id}/lines/{$line->id}/status",
            ['status' => 'preparing'],
        );

        $response->assertOk();
        $response->assertJsonPath('data.line.status', 'preparing');
    }

    public function test_update_line_status_preparing_to_ready(): void
    {
        $order = $this->createOrderSentToKitchen();
        $line = $order->lines->first();

        // First transition to Preparing
        $line->update(['status' => OrderLineStatus::Preparing]);

        $response = $this->patchJson(
            "/api/v1/pos/kitchen/orders/{$order->id}/lines/{$line->id}/status",
            ['status' => 'ready'],
        );

        $response->assertOk();
        $response->assertJsonPath('data.line.status', 'ready');
        $this->assertNotNull($response->json('data.line.prepared_at'));
    }

    public function test_invalid_line_status_transition_fails(): void
    {
        $order = $this->createOrderSentToKitchen();
        $line = $order->lines->first();

        // Sent → Served is not allowed (must go Sent → Preparing → Ready → Served)
        $response = $this->patchJson(
            "/api/v1/pos/kitchen/orders/{$order->id}/lines/{$line->id}/status",
            ['status' => 'served'],
        );

        $response->assertStatus(422);
    }

    public function test_all_lines_ready_auto_transitions_order_to_ready(): void
    {
        $order = $this->createOrderSentToKitchen();
        $line = $order->lines->first();

        // Transition line: Sent → Preparing → Ready
        $this->patchJson(
            "/api/v1/pos/kitchen/orders/{$order->id}/lines/{$line->id}/status",
            ['status' => 'preparing'],
        );

        $this->patchJson(
            "/api/v1/pos/kitchen/orders/{$order->id}/lines/{$line->id}/status",
            ['status' => 'ready'],
        );

        $order->refresh();
        $this->assertEquals(OrderStatus::Ready, $order->status);
        $this->assertNotNull($order->ready_at);
    }

    public function test_bump_order_marks_all_lines_ready(): void
    {
        $order = $this->createOrderSentToKitchen();

        // Add a second line
        OrderLine::create([
            'order_id' => $order->id,
            'line_number' => 2,
            'product_id' => $this->product->id,
            'product_name' => 'Latte',
            'quantity' => '1.000',
            'unit_price' => '8.000',
            'discount_amount' => '0.000',
            'tax_rate' => '19.00',
            'tax_amount' => '1.277',
            'line_total' => '8.000',
            'status' => OrderLineStatus::Sent,
            'sent_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/pos/kitchen/orders/{$order->id}/bump");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ready');

        $order->refresh();
        $this->assertEquals(OrderStatus::Ready, $order->status);
        $this->assertNotNull($order->ready_at);

        // All lines should be Ready
        $order->lines->each(function (OrderLine $line) {
            $this->assertEquals(OrderLineStatus::Ready, $line->status);
            $this->assertNotNull($line->prepared_at);
        });
    }

    public function test_mark_order_served(): void
    {
        $order = $this->createOrderSentToKitchen();

        // Bump to Ready first
        $this->postJson("/api/v1/pos/kitchen/orders/{$order->id}/bump");

        $response = $this->postJson("/api/v1/pos/orders/{$order->id}/served");

        $response->assertOk();
        $this->assertNotNull($response->json('data.served_at'));

        $order->refresh();
        $this->assertNotNull($order->served_at);

        // Lines should be Served
        $order->lines->each(function (OrderLine $line) {
            $this->assertEquals(OrderLineStatus::Served, $line->status);
        });
    }

    public function test_mark_served_fails_when_not_ready(): void
    {
        $order = $this->createOrderSentToKitchen();

        $response = $this->postJson("/api/v1/pos/orders/{$order->id}/served");

        $response->assertStatus(422);
    }

    public function test_cancel_line_from_any_status(): void
    {
        $order = $this->createOrderSentToKitchen();
        $line = $order->lines->first();

        $response = $this->patchJson(
            "/api/v1/pos/kitchen/orders/{$order->id}/lines/{$line->id}/status",
            ['status' => 'cancelled'],
        );

        $response->assertOk();
        $response->assertJsonPath('data.line.status', 'cancelled');
    }

    public function test_order_table_assignment_on_create(): void
    {
        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'table_number' => 'T1',
            'seats' => 4,
            'status' => TableStatus::Available,
        ]);

        $response = $this->postJson('/api/v1/pos/orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'table_id' => $table->id,
            'consumption_mode' => 'SUR_PLACE',
        ]);

        $response->assertStatus(201);

        $table->refresh();
        $this->assertEquals(TableStatus::Occupied, $table->status);
        $this->assertNotNull($table->current_order_id);
    }

    public function test_table_released_on_order_close(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 disposition — POST /api/v1/pos/orders/{id}/close retired. '.
            'The order-close → SALE_RECEIPT path is retired; table-release on close moves to '.
            'device-authority cascade (Task 29 + §18). `test_table_released_on_order_cancel` below '.
            'still exercises the cancel-path table release, which is the surviving non-receipt termination.',
        );
    }

    public function test_table_released_on_order_cancel(): void
    {
        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'table_number' => 'T1',
            'seats' => 4,
            'status' => TableStatus::Available,
        ]);

        $response = $this->postJson('/api/v1/pos/orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'table_id' => $table->id,
        ]);

        $orderId = $response->json('data.id');

        $this->postJson("/api/v1/pos/orders/{$orderId}/cancel", [
            'reason' => 'Customer left',
        ]);

        $table->refresh();
        $this->assertEquals(TableStatus::Available, $table->status);
        $this->assertNull($table->current_order_id);
    }

    public function test_cannot_assign_occupied_table(): void
    {
        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'table_number' => 'T1',
            'seats' => 4,
            'status' => TableStatus::Occupied,
        ]);

        $response = $this->postJson('/api/v1/pos/orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'table_id' => $table->id,
        ]);

        $response->assertStatus(422);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private function createOrderSentToKitchen(): Order
    {
        /** @var Order $order */
        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'order_number' => '#001',
            'status' => OrderStatus::SentToKitchen,
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name,
            'subtotal' => '8.403',
            'tax_amount' => '1.597',
            'discount_amount' => '0.000',
            'total' => '10.000',
            'currency' => 'TND',
            'opened_at' => now()->subMinutes(5),
            'sent_at' => now(),
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'line_number' => 1,
            'product_id' => $this->product->id,
            'product_name' => 'Test Coffee',
            'quantity' => '1.000',
            'unit_price' => '10.000',
            'discount_amount' => '0.000',
            'tax_rate' => '19.00',
            'tax_amount' => '1.597',
            'line_total' => '10.000',
            'status' => OrderLineStatus::Sent,
            'sent_at' => now(),
        ]);

        return $order->load('lines');
    }

    private function createOrderWithStatus(OrderStatus $status): Order
    {
        /** @var Order $order */
        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'order_number' => '#'.str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT),
            'status' => $status,
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name,
            'subtotal' => '8.403',
            'tax_amount' => '1.597',
            'discount_amount' => '0.000',
            'total' => '10.000',
            'currency' => 'TND',
            'opened_at' => now()->subMinutes(10),
            'sent_at' => $status !== OrderStatus::Open ? now()->subMinutes(5) : null,
            'closed_at' => $status === OrderStatus::Closed ? now() : null,
        ]);

        return $order;
    }

    private function setupTestData(): void
    {
        // Session B lane Q-9: the KDS routes are now gated on `module:Menu`
        // (rule 12, mirroring the FE ModuleGuard). The default factory vertical
        // is `retail`, which has no Menu — the fixture must name an F&B
        // vertical for this suite to exercise its own surface.
        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Restaurant]);
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        Permission::findOrCreate('pos.manage_tables', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');
        $this->user->givePermissionTo('pos.manage_tables');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Coffee',
            'sale_price' => '10.0000',
            'tax_rate' => '19.00',
        ]);
    }
}
