<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\HeldOrderService;
use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\HeldOrder;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for the HeldOrderService.
 *
 * Tests service-level logic for holding, recalling, listing, and expiring orders.
 */
final class HeldOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Terminal $terminal;

    private Shift $shift;

    private HeldOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupTestData();

        $companyContext = new CompanyContext;
        $companyContext->setCompanyId($this->company->id);

        $this->service = new HeldOrderService($companyContext);
    }

    public function test_hold_order_creates_a_held_order(): void
    {
        $heldOrder = $this->service->holdOrder(
            terminalId: $this->terminal->id,
            shiftId: $this->shift->id,
            cashierId: $this->user->id,
            cartSnapshot: $this->makeCartSnapshot(),
            label: 'Customer John',
        );

        $this->assertInstanceOf(HeldOrder::class, $heldOrder);
        $this->assertEquals('Customer John', $heldOrder->label);
        $this->assertEquals(HeldOrderStatus::Held, $heldOrder->status);
        $this->assertEquals($this->terminal->id, $heldOrder->terminal_id);
        $this->assertEquals($this->shift->id, $heldOrder->shift_id);
        $this->assertEquals($this->user->id, $heldOrder->cashier_id);
        $this->assertEquals($this->company->id, $heldOrder->company_id);
        $this->assertEquals($this->tenant->id, $heldOrder->tenant_id);
        $this->assertNotNull($heldOrder->held_at);
        $this->assertNotNull($heldOrder->expires_at);
    }

    public function test_hold_order_with_null_label(): void
    {
        $heldOrder = $this->service->holdOrder(
            terminalId: $this->terminal->id,
            shiftId: $this->shift->id,
            cashierId: $this->user->id,
            cartSnapshot: $this->makeCartSnapshot(),
            label: null,
        );

        $this->assertNull($heldOrder->label);
    }

    public function test_hold_order_with_custom_expiry(): void
    {
        $heldOrder = $this->service->holdOrder(
            terminalId: $this->terminal->id,
            shiftId: $this->shift->id,
            cashierId: $this->user->id,
            cartSnapshot: $this->makeCartSnapshot(),
            label: null,
            expiresInMinutes: 60,
        );

        $expectedExpiry = $heldOrder->held_at->copy()->addMinutes(60);
        $this->assertTrue(
            $heldOrder->expires_at->diffInSeconds($expectedExpiry) < 2,
            'Expiry should be approximately 60 minutes from held_at.'
        );
    }

    public function test_hold_order_with_null_expiry(): void
    {
        $heldOrder = $this->service->holdOrder(
            terminalId: $this->terminal->id,
            shiftId: $this->shift->id,
            cashierId: $this->user->id,
            cartSnapshot: $this->makeCartSnapshot(),
            label: null,
            expiresInMinutes: null,
        );

        $this->assertNull($heldOrder->expires_at);
    }

    public function test_hold_order_fails_with_empty_lines(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot hold an empty cart');

        $this->service->holdOrder(
            terminalId: $this->terminal->id,
            shiftId: $this->shift->id,
            cashierId: $this->user->id,
            cartSnapshot: ['lines' => []],
            label: null,
        );
    }

    public function test_hold_order_fails_with_no_lines_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->holdOrder(
            terminalId: $this->terminal->id,
            shiftId: $this->shift->id,
            cashierId: $this->user->id,
            cartSnapshot: ['customer' => null],
            label: null,
        );
    }

    public function test_recall_order_changes_status_to_recalled(): void
    {
        $heldOrder = $this->createHeldOrder();

        $recalled = $this->service->recallOrder($heldOrder->id);

        $this->assertEquals(HeldOrderStatus::Recalled, $recalled->status);
        $this->assertNotNull($recalled->recalled_at);
    }

    public function test_recall_already_recalled_order_throws(): void
    {
        $heldOrder = $this->createHeldOrder([
            'status' => HeldOrderStatus::Recalled,
            'recalled_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already been recalled');

        $this->service->recallOrder($heldOrder->id);
    }

    public function test_recall_expired_order_throws(): void
    {
        $heldOrder = $this->createHeldOrder([
            'expires_at' => now()->subMinutes(10),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired');

        $this->service->recallOrder($heldOrder->id);
    }

    public function test_discard_order_deletes_from_database(): void
    {
        $heldOrder = $this->createHeldOrder();

        $this->service->discardOrder($heldOrder->id);

        $this->assertDatabaseMissing('pos_held_orders', [
            'id' => $heldOrder->id,
        ]);
    }

    public function test_list_held_orders_returns_only_active(): void
    {
        $this->createHeldOrder(['label' => 'Active 1']);
        $this->createHeldOrder(['label' => 'Active 2']);
        $this->createHeldOrder([
            'label' => 'Recalled',
            'status' => HeldOrderStatus::Recalled,
        ]);
        $this->createHeldOrder([
            'label' => 'Expired Status',
            'status' => HeldOrderStatus::Expired,
        ]);

        $result = $this->service->listHeldOrders($this->terminal->id);

        $this->assertCount(2, $result);
    }

    public function test_list_held_orders_excludes_past_expiry(): void
    {
        $this->createHeldOrder(['label' => 'Active']);
        $this->createHeldOrder([
            'label' => 'Past Expiry',
            'expires_at' => now()->subMinutes(5),
        ]);

        $result = $this->service->listHeldOrders($this->terminal->id);

        $this->assertCount(1, $result);
        $this->assertEquals('Active', $result->first()->label);
    }

    public function test_list_held_orders_includes_null_expiry(): void
    {
        $this->createHeldOrder([
            'label' => 'No Expiry',
            'expires_at' => null,
        ]);

        $result = $this->service->listHeldOrders($this->terminal->id);

        $this->assertCount(1, $result);
    }

    public function test_list_held_orders_filters_by_shift(): void
    {
        $this->createHeldOrder(['label' => 'Shift 1']);

        $otherShift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 2,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        $this->createHeldOrder([
            'label' => 'Shift 2',
            'shift_id' => $otherShift->id,
        ]);

        $result = $this->service->listHeldOrders($this->terminal->id, $this->shift->id);

        $this->assertCount(1, $result);
        $this->assertEquals('Shift 1', $result->first()->label);
    }

    public function test_expire_orders_sets_status_to_expired(): void
    {
        $this->createHeldOrder([
            'label' => 'Should Expire',
            'expires_at' => now()->subMinutes(10),
        ]);
        $this->createHeldOrder([
            'label' => 'Should Not Expire',
            'expires_at' => now()->addHours(2),
        ]);
        $this->createHeldOrder([
            'label' => 'No Expiry',
            'expires_at' => null,
        ]);

        $count = $this->service->expireOrders();

        $this->assertEquals(1, $count);

        $this->assertDatabaseHas('pos_held_orders', [
            'label' => 'Should Expire',
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('pos_held_orders', [
            'label' => 'Should Not Expire',
            'status' => 'held',
        ]);
        $this->assertDatabaseHas('pos_held_orders', [
            'label' => 'No Expiry',
            'status' => 'held',
        ]);
    }

    public function test_expire_orders_returns_zero_when_none_expired(): void
    {
        $this->createHeldOrder(['expires_at' => now()->addHours(4)]);

        $count = $this->service->expireOrders();

        $this->assertEquals(0, $count);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeCartSnapshot(): array
    {
        return [
            'lines' => [
                [
                    'product_id' => 'prod-001',
                    'product_name' => 'Espresso',
                    'quantity' => 2,
                    'unit_price' => '3.50',
                    'discount_amount' => '0.00',
                    'tax_rate' => '20.00',
                    'modifiers' => [],
                    'special_instructions' => null,
                ],
            ],
            'customer' => null,
            'consumption_mode' => null,
            'notes' => null,
            'discount' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createHeldOrder(array $overrides = []): HeldOrder
    {
        return HeldOrder::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'cashier_id' => $this->user->id,
            'label' => 'Test Order',
            'cart_snapshot' => $this->makeCartSnapshot(),
            'status' => HeldOrderStatus::Held,
            'held_at' => now(),
            'expires_at' => now()->addHours(4),
        ], $overrides));
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

        $location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }
}
