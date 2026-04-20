<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\HeldOrder;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for the POS Held Orders (cart parking) API.
 *
 * Tests the complete HTTP flow for hold, list, recall, and discard operations.
 */
final class HeldOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    public function test_hold_order_creates_held_order_successfully(): void
    {
        $response = $this->postJson('/api/v1/pos/held-orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'label' => 'Table 5',
            'cart_snapshot' => $this->makeCartSnapshot(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'terminal_id',
                'shift_id',
                'cashier_id',
                'label',
                'cart_snapshot',
                'status',
                'held_at',
                'expires_at',
                'line_count',
                'total',
                'created_at',
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals('Table 5', $data['label']);
        $this->assertEquals('held', $data['status']);
        $this->assertEquals(2, $data['line_count']);
        $this->assertEquals($this->user->id, $data['cashier_id']);

        $this->assertDatabaseHas('pos_held_orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'cashier_id' => $this->user->id,
            'label' => 'Table 5',
            'status' => 'held',
        ]);
    }

    public function test_hold_order_without_label(): void
    {
        $response = $this->postJson('/api/v1/pos/held-orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'cart_snapshot' => $this->makeCartSnapshot(),
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.label'));
    }

    public function test_hold_order_with_custom_expiry(): void
    {
        $response = $this->postJson('/api/v1/pos/held-orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'cart_snapshot' => $this->makeCartSnapshot(),
            'expires_in_minutes' => 60,
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.expires_at'));
    }

    public function test_hold_order_fails_with_empty_cart(): void
    {
        $response = $this->postJson('/api/v1/pos/held-orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'cart_snapshot' => [
                'lines' => [],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_hold_order_fails_without_terminal_id(): void
    {
        $response = $this->postJson('/api/v1/pos/held-orders', [
            'shift_id' => $this->shift->id,
            'cart_snapshot' => $this->makeCartSnapshot(),
        ]);

        $response->assertStatus(422);
    }

    public function test_list_held_orders_returns_active_orders(): void
    {
        $this->createHeldOrder(['label' => 'Order A']);
        $this->createHeldOrder(['label' => 'Order B']);

        // Create a recalled order (should not appear)
        $this->createHeldOrder([
            'label' => 'Recalled',
            'status' => HeldOrderStatus::Recalled,
        ]);

        $response = $this->getJson('/api/v1/pos/held-orders?terminal_id='.$this->terminal->id);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_list_held_orders_filters_by_shift(): void
    {
        $this->createHeldOrder(['label' => 'Current Shift']);

        $otherShift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 2,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHours(2),
        ]);

        $this->createHeldOrder([
            'label' => 'Other Shift',
            'shift_id' => $otherShift->id,
        ]);

        $response = $this->getJson(
            '/api/v1/pos/held-orders?terminal_id='.$this->terminal->id.'&shift_id='.$this->shift->id
        );

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('Current Shift', $response->json('data.0.label'));
    }

    public function test_list_held_orders_excludes_expired(): void
    {
        $this->createHeldOrder(['label' => 'Active']);
        $this->createHeldOrder([
            'label' => 'Expired',
            'expires_at' => now()->subMinutes(10),
        ]);

        $response = $this->getJson('/api/v1/pos/held-orders?terminal_id='.$this->terminal->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('Active', $response->json('data.0.label'));
    }

    public function test_show_held_order(): void
    {
        $heldOrder = $this->createHeldOrder(['label' => 'Show Me']);

        $response = $this->getJson('/api/v1/pos/held-orders/'.$heldOrder->id);

        $response->assertStatus(200);
        $this->assertEquals('Show Me', $response->json('data.label'));
    }

    public function test_recall_held_order(): void
    {
        $heldOrder = $this->createHeldOrder(['label' => 'Recall Me']);

        $response = $this->postJson('/api/v1/pos/held-orders/'.$heldOrder->id.'/recall');

        $response->assertStatus(200);
        $this->assertEquals('recalled', $response->json('data.status'));
        $this->assertNotNull($response->json('data.recalled_at'));

        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $heldOrder->id,
            'status' => 'recalled',
        ]);
    }

    public function test_recall_already_recalled_order_fails(): void
    {
        $heldOrder = $this->createHeldOrder([
            'status' => HeldOrderStatus::Recalled,
            'recalled_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/pos/held-orders/'.$heldOrder->id.'/recall');

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'RECALL_FAILED');
    }

    public function test_recall_expired_order_fails(): void
    {
        $heldOrder = $this->createHeldOrder([
            'expires_at' => now()->subMinutes(10),
        ]);

        $response = $this->postJson('/api/v1/pos/held-orders/'.$heldOrder->id.'/recall');

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'RECALL_FAILED');
    }

    public function test_discard_held_order(): void
    {
        $heldOrder = $this->createHeldOrder(['label' => 'Discard Me']);

        $response = $this->deleteJson('/api/v1/pos/held-orders/'.$heldOrder->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.success', true);

        $this->assertDatabaseMissing('pos_held_orders', [
            'id' => $heldOrder->id,
        ]);
    }

    public function test_discard_nonexistent_order_returns_404(): void
    {
        $response = $this->deleteJson('/api/v1/pos/held-orders/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
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
                [
                    'product_id' => 'prod-002',
                    'product_name' => 'Croissant',
                    'quantity' => 1,
                    'unit_price' => '2.00',
                    'discount_amount' => '0.00',
                    'tax_rate' => '10.00',
                    'modifiers' => [],
                    'special_instructions' => null,
                ],
            ],
            'customer' => null,
            'consumption_mode' => 'SUR_PLACE',
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
    }
}
