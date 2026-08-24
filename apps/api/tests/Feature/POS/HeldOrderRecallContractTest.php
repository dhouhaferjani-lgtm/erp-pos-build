<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\HeldOrder;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Q-8 — the held-order recall/discard WIRE contract.
 *
 * `HeldOrderTenantIsolationTest` (.052) pins the cross-tenant 404s; this class
 * pins what Q-8 added on top of them:
 *
 *   - `terminal_id` is an OPTIONAL body field on `POST .../{id}/recall`. It is
 *     optional because no shipped client sends a body on this route
 *     (`apps/web/src/features/pos/api/heldOrderApi.ts:106`,
 *     `apps/pos/src/api/holdApi.ts:40`), so requiring it would 404 every live
 *     recall. When supplied it must be honoured: a basket parked on till A is
 *     never listed on till B, so a recall claiming till B must miss the scope.
 *   - a lost race is a 409 `HELD_ORDER_RECALL_CONFLICT`, not a second copy of
 *     the cart snapshot and not the 422 used for stale-but-well-defined
 *     refusals.
 *   - discard soft-deletes and records the authenticated actor, and refuses a
 *     RECALLED order with 422 rather than destroying the evidence.
 */
final class HeldOrderRecallContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Terminal $terminal;

    private Terminal $otherTerminal;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant q8-recall',
            'slug' => 'q8-recall',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Q8 Cashier',
            'email' => 'q8-recall@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->user->assignRole('admin');

        UserCompanyMembership::firstOrCreate(
            ['user_id' => $this->user->id, 'company_id' => $this->company->id],
            ['role' => 'admin'],
        );

        $this->terminal = $this->makeTerminal('TILL-A');
        $this->otherTerminal = $this->makeTerminal('TILL-B');

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    public function test_recall_without_a_terminal_id_still_works(): void
    {
        $heldOrder = $this->createHeldOrder();

        $this->actingAsCashier()
            ->postJson('/api/v1/pos/held-orders/'.$heldOrder->id.'/recall')
            ->assertStatus(200)
            ->assertJsonPath('data.status', HeldOrderStatus::Recalled->value);
    }

    public function test_recall_on_the_holding_terminal_succeeds(): void
    {
        $heldOrder = $this->createHeldOrder();

        $this->actingAsCashier()
            ->postJson('/api/v1/pos/held-orders/'.$heldOrder->id.'/recall', [
                'terminal_id' => $this->terminal->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', HeldOrderStatus::Recalled->value);
    }

    public function test_recall_claiming_another_terminal_is_scoped_out(): void
    {
        $heldOrder = $this->createHeldOrder();

        $this->actingAsCashier()
            ->postJson('/api/v1/pos/held-orders/'.$heldOrder->id.'/recall', [
                'terminal_id' => $this->otherTerminal->id,
            ])
            ->assertStatus(404);

        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $heldOrder->id,
            'status' => HeldOrderStatus::Held->value,
            'recalled_at' => null,
        ]);
    }

    public function test_recall_rejects_a_malformed_terminal_id(): void
    {
        $heldOrder = $this->createHeldOrder();

        $this->actingAsCashier()
            ->postJson('/api/v1/pos/held-orders/'.$heldOrder->id.'/recall', [
                'terminal_id' => 'not-a-uuid',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $heldOrder->id,
            'status' => HeldOrderStatus::Held->value,
        ]);
    }

    /**
     * A lost race is 409, not a second snapshot. The interleaving is staged
     * deterministically via the `retrieved` model event, which fires between
     * the service's locking SELECT and its conditional UPDATE — see
     * `HeldOrderServiceTest::test_recall_refuses_when_the_row_is_flipped_after_the_read`
     * for why a two-connection race cannot be staged under RefreshDatabase.
     */
    public function test_a_lost_recall_race_is_a_409_conflict(): void
    {
        $heldOrder = $this->createHeldOrder();

        $flipped = false;
        HeldOrder::retrieved(function (HeldOrder $model) use ($heldOrder, &$flipped): void {
            if ($flipped || $model->id !== $heldOrder->id) {
                return;
            }
            $flipped = true;

            DB::table('pos_held_orders')
                ->where('id', $heldOrder->id)
                ->update(['status' => HeldOrderStatus::Recalled->value, 'recalled_at' => now()]);
        });

        try {
            $this->actingAsCashier()
                ->postJson('/api/v1/pos/held-orders/'.$heldOrder->id.'/recall', [
                    'terminal_id' => $this->terminal->id,
                ])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'HELD_ORDER_RECALL_CONFLICT');
        } finally {
            HeldOrder::flushEventListeners();
        }
    }

    public function test_discard_soft_deletes_and_records_the_authenticated_actor(): void
    {
        $heldOrder = $this->createHeldOrder();

        $this->actingAsCashier()
            ->deleteJson('/api/v1/pos/held-orders/'.$heldOrder->id)
            ->assertStatus(200)
            ->assertJsonPath('data.success', true);

        $this->assertSoftDeleted('pos_held_orders', ['id' => $heldOrder->id]);

        $row = DB::table('pos_held_orders')->where('id', $heldOrder->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->user->id, $row->discarded_by);
    }

    public function test_discard_of_a_recalled_order_is_refused(): void
    {
        $heldOrder = $this->createHeldOrder([
            'status' => HeldOrderStatus::Recalled,
            'recalled_at' => now(),
        ]);

        $this->actingAsCashier()
            ->deleteJson('/api/v1/pos/held-orders/'.$heldOrder->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'HELD_ORDER_DISCARD_REFUSED');

        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $heldOrder->id,
            'deleted_at' => null,
        ]);
    }

    public function test_a_discarded_order_can_no_longer_be_recalled_or_listed(): void
    {
        $heldOrder = $this->createHeldOrder();

        $this->actingAsCashier()
            ->deleteJson('/api/v1/pos/held-orders/'.$heldOrder->id)
            ->assertStatus(200);

        $this->actingAsCashier()
            ->getJson('/api/v1/pos/held-orders?terminal_id='.$this->terminal->id)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAsCashier()
            ->postJson('/api/v1/pos/held-orders/'.$heldOrder->id.'/recall')
            ->assertStatus(404);
    }

    private function actingAsCashier(): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        /** @var self */
        return $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id);
    }

    private function makeTerminal(string $code): Terminal
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Loc '.$code,
            'code' => strtoupper(Str::random(6)),
            'type' => 'shop',
            'address_country' => 'TN',
            'is_active' => true,
        ]);

        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'type' => TerminalType::Physical,
            'code' => $code.'-'.strtoupper(Str::random(4)),
            'name' => 'Terminal '.$code,
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => (int) date('Y'),
            'is_active' => true,
            'fiscal_schema_version' => 2,
        ]);
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
            'label' => 'Table 5',
            'cart_snapshot' => [
                'lines' => [[
                    'product_id' => 'prod-001',
                    'product_name' => 'Espresso',
                    'quantity' => '2.0000',
                    'unit_price' => '3.500',
                    'discount_amount' => '0.000',
                    'tax_rate' => '20.00',
                ]],
            ],
            'status' => HeldOrderStatus::Held,
            'held_at' => now(),
            'expires_at' => now()->addHours(4),
        ], $overrides));
    }
}
