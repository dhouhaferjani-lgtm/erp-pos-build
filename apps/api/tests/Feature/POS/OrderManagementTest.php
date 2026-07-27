<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\OrderLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Infrastructure\Broadcasting\OrderSentToKitchenBroadcast;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for the POS Order Management API.
 *
 * Tests the full order lifecycle: create, add lines, send to kitchen, close, cancel.
 */
final class OrderManagementTest extends TestCase
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

    public function test_create_order_succeeds(): void
    {
        $response = $this->postJson('/api/v1/pos/orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'terminal_id',
                'shift_id',
                'order_number',
                'status',
                'cashier_id',
                'cashier_name',
                'subtotal',
                'tax_amount',
                'total',
                'currency',
                'opened_at',
                'lines',
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals('open', $data['status']);
        $this->assertEquals('#001', $data['order_number']);
        $this->assertEquals($this->user->id, $data['cashier_id']);
    }

    public function test_create_order_with_customer_and_consumption_mode(): void
    {
        $response = $this->postJson('/api/v1/pos/orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'customer_name' => 'John Doe',
            'consumption_mode' => 'SUR_PLACE',
            'notes' => 'VIP customer',
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('John Doe', $data['customer_name']);
        $this->assertEquals('SUR_PLACE', $data['consumption_mode']);
        $this->assertEquals('VIP customer', $data['notes']);
    }

    public function test_create_order_generates_sequential_numbers(): void
    {
        $this->postJson('/api/v1/pos/orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
        ])->assertStatus(201);

        $response = $this->postJson('/api/v1/pos/orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
        ]);

        $response->assertStatus(201);
        $this->assertEquals('#002', $response->json('data.order_number'));
    }

    public function test_create_order_fails_without_terminal(): void
    {
        $response = $this->postJson('/api/v1/pos/orders', [
            'shift_id' => $this->shift->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_add_line_to_order(): void
    {
        $order = $this->createTestOrder();

        $response = $this->postJson("/api/v1/pos/orders/{$order->id}/lines", [
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => '10.000',
            'tax_rate' => '19.00',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'line' => ['id', 'product_id', 'quantity', 'unit_price', 'line_total'],
                'order' => ['id', 'subtotal', 'tax_amount', 'total', 'lines'],
            ],
        ]);

        $lineData = $response->json('data.line');
        $this->assertEquals($this->product->id, $lineData['product_id']);
        $this->assertEquals('2.0000', $lineData['quantity']);

        // Order totals should be updated
        $orderData = $response->json('data.order');
        $this->assertNotEquals('0.000', $orderData['total']);
    }

    public function test_add_line_fails_on_closed_order(): void
    {
        $order = $this->createTestOrder();
        $order->update(['status' => OrderStatus::Closed, 'closed_at' => now()]);

        $response = $this->postJson("/api/v1/pos/orders/{$order->id}/lines", [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => '10.000',
            'tax_rate' => '19.00',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('ADD_LINE_FAILED', $response->json('error.code'));
    }

    public function test_modify_line_on_order(): void
    {
        $order = $this->createTestOrder();
        $this->addLineToOrder($order);
        $line = $order->lines()->first();

        $response = $this->patchJson(
            "/api/v1/pos/orders/{$order->id}/lines/{$line->id}",
            ['quantity' => 5]
        );

        $response->assertStatus(200);
        $this->assertEquals('5.0000', $response->json('data.line.quantity'));
    }

    public function test_remove_line_from_order(): void
    {
        $order = $this->createTestOrder();
        $this->addLineToOrder($order);
        $line = $order->lines()->first();

        $response = $this->deleteJson(
            "/api/v1/pos/orders/{$order->id}/lines/{$line->id}"
        );

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data.lines'));
        $this->assertEquals('0.000', $response->json('data.total'));
    }

    public function test_send_order_to_kitchen(): void
    {
        $order = $this->createTestOrder();
        $this->addLineToOrder($order);

        $response = $this->postJson("/api/v1/pos/orders/{$order->id}/send-to-kitchen");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('sent_to_kitchen', $data['status']);
        $this->assertNotNull($data['sent_at']);

        // Lines should be marked as sent
        $lineStatuses = collect($data['lines'])->pluck('status')->unique()->all();
        $this->assertEquals(['sent'], $lineStatuses);
    }

    public function test_send_to_kitchen_batches_product_unit_queries_for_multiple_serialized_lines(): void
    {
        $order = $this->createTestOrder();
        $this->addPrecisionLines($order);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson("/api/v1/pos/orders/{$order->id}/send-to-kitchen");

        $queries = array_values(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertSame([0, 2, 3], collect($response->json('data.lines'))->pluck('quantity_decimals')->all());

        $productQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'from "products"'),
        ));
        $unitQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'from "units"'),
        ));

        $queryDump = json_encode($queries, JSON_THROW_ON_ERROR);
        // One batch for the response and, when after-commit broadcasting runs
        // synchronously in this environment, one batch for the broadcast.
        $this->assertLessThanOrEqual(2, count($productQueries), $queryDump);
        $this->assertLessThanOrEqual(2, count($unitQueries), $queryDump);
    }

    public function test_sent_to_kitchen_broadcast_batches_product_unit_queries_for_multiple_lines(): void
    {
        $order = $this->createTestOrder();
        $this->addPrecisionLines($order);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $payload = (new OrderSentToKitchenBroadcast(
            orderId: $order->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
        ))->broadcastWith();
        /** @var array{order: array{lines: array<int, array{quantity_decimals: int}>}} $serializedPayload */
        $serializedPayload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        $queries = array_values(DB::getQueryLog());
        DB::disableQueryLog();

        /** @var array<int, array{quantity_decimals: int}> $lines */
        $lines = $serializedPayload['order']['lines'];
        $this->assertSame([0, 2, 3], collect($lines)->pluck('quantity_decimals')->all());

        $productQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'from "products"'),
        ));
        $unitQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'from "units"'),
        ));

        $queryDump = json_encode($queries, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $productQueries, $queryDump);
        $this->assertCount(1, $unitQueries, $queryDump);
    }

    public function test_send_to_kitchen_fails_without_lines(): void
    {
        $order = $this->createTestOrder();

        $response = $this->postJson("/api/v1/pos/orders/{$order->id}/send-to-kitchen");

        $response->assertStatus(422);
        $this->assertEquals('SEND_TO_KITCHEN_FAILED', $response->json('error.code'));
    }

    public function test_cancel_order(): void
    {
        $order = $this->createTestOrder();

        $response = $this->postJson("/api/v1/pos/orders/{$order->id}/cancel", [
            'reason' => 'Customer left',
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('cancelled', $data['status']);
        $this->assertNotNull($data['cancelled_at']);
    }

    public function test_cancel_closed_order_fails(): void
    {
        $order = $this->createTestOrder();
        $order->update(['status' => OrderStatus::Closed, 'closed_at' => now()]);

        $response = $this->postJson("/api/v1/pos/orders/{$order->id}/cancel");

        $response->assertStatus(422);
        $this->assertEquals('CANCEL_ORDER_FAILED', $response->json('error.code'));
    }

    public function test_list_orders_with_filters(): void
    {
        $this->createTestOrder();
        $this->createTestOrder();

        $response = $this->getJson('/api/v1/pos/orders?terminal_id='.$this->terminal->id);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure(['meta' => ['current_page', 'total']]);
    }

    public function test_list_orders_filter_by_status(): void
    {
        $order1 = $this->createTestOrder();
        $order2 = $this->createTestOrder();
        $order2->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

        $response = $this->getJson('/api/v1/pos/orders?status=open');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_list_active_orders(): void
    {
        $this->createTestOrder();
        $cancelled = $this->createTestOrder();
        $cancelled->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

        $response = $this->getJson('/api/v1/pos/orders?active=true');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_show_order_with_lines(): void
    {
        $order = $this->createTestOrder();
        $this->addLineToOrder($order);

        $response = $this->getJson("/api/v1/pos/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'order_number',
                'status',
                'lines' => [
                    '*' => ['id', 'product_name', 'quantity', 'line_total'],
                ],
            ],
        ]);
    }

    /**
     * Create a test order via the service.
     */
    private function createTestOrder(): Order
    {
        /** @var Order $order */
        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'order_number' => '#'.str_pad((string) (Order::where('terminal_id', $this->terminal->id)->count() + 1), 3, '0', STR_PAD_LEFT),
            'status' => OrderStatus::Open,
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name ?? 'Test Cashier',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '0.000',
            'currency' => 'TND',
            'opened_at' => now(),
        ]);

        return $order;
    }

    /**
     * Add a test line to an order via API.
     */
    private function addLineToOrder(Order $order): void
    {
        $this->postJson("/api/v1/pos/orders/{$order->id}/lines", [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => '10.000',
            'tax_rate' => '19.00',
        ])->assertStatus(201);
    }

    private function addPrecisionLines(Order $order): void
    {
        foreach ([0, 2, 3] as $index => $decimalPlaces) {
            $unit = Unit::factory()->create(['decimal_places' => $decimalPlaces]);
            $product = Product::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'unit_id' => $unit->id,
            ]);

            OrderLine::create([
                'order_id' => $order->id,
                'line_number' => $index + 1,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => '1.0000',
                'unit_price' => '10.000',
                'discount_amount' => '0.000',
                'tax_rate' => '0.00',
                'tax_amount' => '0.000',
                'line_total' => '10.000',
                'status' => OrderLineStatus::Pending,
            ]);
        }
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
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
        $this->user->givePermissionTo('pos.operate_terminal');

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
