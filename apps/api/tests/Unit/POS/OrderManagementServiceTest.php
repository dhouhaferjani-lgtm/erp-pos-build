<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\OrderManagementService;
use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\OrderLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for OrderManagementService.
 */
final class OrderManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderManagementService $service;

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

        $companyContext = new CompanyContext();
        $companyContext->setCompanyId($this->company->id);

        $scaleResolver = $this->createMock(CurrencyScaleResolverInterface::class);
        $scaleResolver->method('getScale')->willReturn(4);

        $this->service = new OrderManagementService(
            $companyContext,
            $scaleResolver,
        );
    }

    public function test_create_order_sets_correct_defaults(): void
    {
        $order = $this->service->createOrder(
            terminalId: $this->terminal->id,
            shiftId: $this->shift->id,
            tableId: null,
            partnerId: null,
            customerName: null,
            consumptionMode: null,
            notes: null,
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals(OrderStatus::Open, $order->status);
        $this->assertEquals('#001', $order->order_number);
        $this->assertEquals($this->terminal->id, $order->terminal_id);
        $this->assertEquals($this->shift->id, $order->shift_id);
        $this->assertEquals($this->user->id, $order->cashier_id);
        $this->assertEquals('0.0000', $order->total);
        $this->assertNotNull($order->opened_at);
    }

    public function test_create_order_increments_order_number(): void
    {
        $order1 = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $order2 = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $this->assertEquals('#001', $order1->order_number);
        $this->assertEquals('#002', $order2->order_number);
    }

    public function test_add_line_calculates_totals(): void
    {
        $order = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $line = $this->service->addLine(
            orderId: $order->id,
            productId: $this->product->id,
            quantity: '2',
            unitPrice: '10.0000',
            taxRate: '19.00',
            discountAmount: null,
            modifiers: null,
            specialInstructions: null,
        );

        $this->assertInstanceOf(OrderLine::class, $line);
        $this->assertEquals('2.000', $line->quantity);
        $this->assertEquals(1, $line->line_number);
        $this->assertEquals(OrderLineStatus::Pending, $line->status);

        // line_total should be 20.0000 (2 * 10)
        $this->assertEquals('20.0000', $line->line_total);

        // Tax at 19% inclusive: net = 20/1.19, tax = 20 - net
        $this->assertNotEquals('0.0000', $line->tax_amount);

        // Order totals should be updated
        $order->refresh();
        $this->assertNotEquals('0.0000', $order->total);
    }

    public function test_add_line_with_discount(): void
    {
        $order = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $line = $this->service->addLine(
            orderId: $order->id,
            productId: $this->product->id,
            quantity: '1',
            unitPrice: '100.0000',
            taxRate: '19.00',
            discountAmount: '10.0000',
            modifiers: null,
            specialInstructions: null,
        );

        // line_total = 100 - 10 = 90
        $this->assertEquals('90.0000', $line->line_total);
        $this->assertEquals('10.0000', $line->discount_amount);
    }

    public function test_modify_line_updates_quantity(): void
    {
        $order = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $line = $this->service->addLine(
            $order->id, $this->product->id,
            '1', '10.0000', '19.00', null, null, null,
        );

        $modified = $this->service->modifyLine(
            orderId: $order->id,
            lineId: $line->id,
            quantity: '3',
            discountAmount: null,
            modifiers: null,
            specialInstructions: null,
        );

        $this->assertEquals('3.000', $modified->quantity);
        $this->assertEquals('30.0000', $modified->line_total);
    }

    public function test_remove_line_recalculates_totals(): void
    {
        $order = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $line = $this->service->addLine(
            $order->id, $this->product->id,
            '1', '10.0000', '19.00', null, null, null,
        );

        $this->service->removeLine($order->id, $line->id);

        $order->refresh();
        $this->assertEquals('0.0000', $order->total);
        $this->assertEquals(0, $order->lines()->count());
    }

    public function test_send_to_kitchen_changes_status(): void
    {
        $order = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $this->service->addLine(
            $order->id, $this->product->id,
            '1', '10.0000', '19.00', null, null, null,
        );

        $sentOrder = $this->service->sendToKitchen($order->id);

        $this->assertEquals(OrderStatus::SentToKitchen, $sentOrder->status);
        $this->assertNotNull($sentOrder->sent_at);

        // All lines should be 'sent'
        $lineStatuses = $sentOrder->lines->pluck('status')->unique()->all();
        $this->assertEquals([OrderLineStatus::Sent], $lineStatuses);
    }

    public function test_send_to_kitchen_fails_without_lines(): void
    {
        $order = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $this->expectException(\RuntimeException::class);
        $this->service->sendToKitchen($order->id);
    }

    public function test_cancel_order_sets_cancelled_status(): void
    {
        $order = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $cancelled = $this->service->cancelOrder($order->id, 'Customer left');

        $this->assertEquals(OrderStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertStringContainsString('Customer left', $cancelled->notes ?? '');
    }

    public function test_cancel_closed_order_throws(): void
    {
        $order = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $order->update(['status' => OrderStatus::Closed, 'closed_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->service->cancelOrder($order->id, null);
    }

    public function test_add_line_to_closed_order_throws(): void
    {
        $order = $this->service->createOrder(
            $this->terminal->id, $this->shift->id,
            null, null, null, null, null,
        );

        $order->update(['status' => OrderStatus::Closed, 'closed_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->service->addLine(
            $order->id, $this->product->id,
            '1', '10.0000', '19.00', null, null, null,
        );
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
