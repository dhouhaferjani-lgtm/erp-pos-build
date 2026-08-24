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
use Illuminate\Database\ConnectionInterface;
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
 *     optional for BACKWARD COMPATIBILITY: the one live client
 *     (`apps/web/src/features/pos/api/heldOrderApi.ts:106`) POSTs bare, so
 *     requiring the field would 404 every live recall today. When supplied it
 *     must be honoured: a basket parked on till A is never listed on till B,
 *     so a recall claiming till B must miss the scope. Because no live client
 *     sends it, the audit item "recall is not terminal-scoped" is NOT closed
 *     by this lane — see `HeldOrderService::recallOrder()`.
 *   - a basket another actor already consumed is a 409
 *     `HELD_ORDER_RECALL_CONFLICT`, on BOTH drivers, never a second copy of the
 *     cart snapshot. A basket that merely lapsed on its own TTL is a 422
 *     `RECALL_FAILED` — see the status→code table on
 *     `HeldOrderService::recallOrder()`.
 *   - discard soft-deletes and records the authenticated actor, and refuses a
 *     RECALLED order with 422 rather than destroying the evidence.
 */
final class HeldOrderRecallContractTest extends TestCase
{
    use RefreshDatabase;

    private const RACE_CONNECTION = 'q8_recall_race';

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
     * The PRODUCTION lost-race shape, staged the way PostgreSQL actually
     * produces it.
     *
     * Under READ COMMITTED, the loser's `SELECT ... FOR UPDATE` blocks on the
     * winner's row lock and, once the winner commits, re-reads the NEW row
     * version (EvalPlanQual) — i.e. it observes `status = 'recalled'` and the
     * guard at `HeldOrderService::recallOrder()` fires BEFORE the conditional
     * UPDATE can. So the loser's observation is reproduced faithfully by
     * letting a SECOND DATABASE CONNECTION commit the claim first: same row
     * version, same guard branch, same response.
     *
     * The fixture row is planted on that second connection (FK triggers
     * suspended, since this test's parents live in the uncommitted
     * RefreshDatabase transaction) so both connections can see it, and is
     * deleted again after the test transaction rolls back.
     *
     * The old shape of this test flipped the row from a `retrieved` model hook
     * on the SAME connection and SAME transaction, which bypasses the row lock
     * entirely — an interleaving two real tills cannot produce on PostgreSQL.
     * That variant survives, honestly renamed, as the SQLite backstop test
     * below.
     */
    public function test_a_lost_recall_race_against_a_second_connection_is_a_409_conflict(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A two-connection race needs PostgreSQL row locks; SQLite is covered by the backstop test.');
        }

        $race = $this->raceConnection();
        $id = (string) Str::uuid();

        // Till A parks the basket (committed, so both connections see it).
        $race->statement("SET session_replication_role = 'replica'");
        $race->table('pos_held_orders')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'cashier_id' => $this->user->id,
            'label' => 'Race basket',
            'cart_snapshot' => json_encode(['lines' => [[
                'product_id' => 'prod-001',
                'product_name' => 'Espresso',
                'quantity' => '2.0000',
                'unit_price' => '3.500',
                'discount_amount' => '0.000',
                'tax_rate' => '20.00',
            ]]], JSON_THROW_ON_ERROR),
            'status' => HeldOrderStatus::Held->value,
            'held_at' => now(),
            'expires_at' => now()->addHours(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $race->statement("SET session_replication_role = 'origin'");

        $this->beforeApplicationDestroyed(function () use ($id): void {
            DB::connection(self::RACE_CONNECTION)->table('pos_held_orders')->where('id', $id)->delete();
            DB::purge(self::RACE_CONNECTION);
        });

        // Till A wins: it takes the row lock and commits the claim.
        $race->transaction(function () use ($race, $id): void {
            $locked = $race->table('pos_held_orders')->where('id', $id)->lockForUpdate()->first();
            $this->assertNotNull($locked);
            $this->assertSame(HeldOrderStatus::Held->value, $locked->status);

            $race->table('pos_held_orders')
                ->where('id', $id)
                ->where('status', HeldOrderStatus::Held->value)
                ->update([
                    'status' => HeldOrderStatus::Recalled->value,
                    'recalled_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        // Till B (this request) resumes and re-reads the committed row version.
        $this->actingAsCashier()
            ->postJson('/api/v1/pos/held-orders/'.$id.'/recall', [
                'terminal_id' => $this->terminal->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HELD_ORDER_RECALL_CONFLICT');

        // The loser wrote nothing: the basket is still till A's single claim.
        $after = $race->table('pos_held_orders')->where('id', $id)->first();
        $this->assertNotNull($after);
        $this->assertSame(HeldOrderStatus::Recalled->value, $after->status);
        $this->assertNull($after->deleted_at);
    }

    /**
     * The SECOND backstop, which is the ONLY defence on SQLite (where
     * `FOR UPDATE` is a no-op): the conditional `UPDATE ... WHERE status =
     * 'held'` affecting zero rows must also surface as 409, never as a silent
     * success and never as 422.
     *
     * The interleaving is staged from a `retrieved` model hook — same
     * connection, same transaction — which is exactly why it reaches the
     * conditional UPDATE instead of the guard: it is NOT how PostgreSQL
     * produces a lost race (see the two-connection test above), it is how the
     * lockless driver does.
     */
    public function test_the_conditional_update_backstop_also_yields_409(): void
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

    /**
     * The other half of the status→code split: a basket that lapsed on its own
     * TTL was consumed by nobody, so it is a 422 `RECALL_FAILED`, not a 409.
     * Refreshing the list will not make it recallable.
     */
    public function test_a_lapsed_basket_is_422_not_409(): void
    {
        $heldOrder = $this->createHeldOrder(['expires_at' => now()->subMinutes(10)]);

        $this->actingAsCashier()
            ->postJson('/api/v1/pos/held-orders/'.$heldOrder->id.'/recall')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RECALL_FAILED');

        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $heldOrder->id,
            'status' => HeldOrderStatus::Held->value,
            'recalled_at' => null,
        ]);
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

    /**
     * A SECOND physical database connection onto the same test database, used
     * to stage the winner of the recall race. `lock_timeout` is bounded so a
     * lock the test failed to release surfaces as an error instead of a hang.
     */
    private function raceConnection(): ConnectionInterface
    {
        /** @var array<string, mixed> $config */
        $config = config('database.connections.'.config('database.default'));
        config(['database.connections.'.self::RACE_CONNECTION => $config]);
        DB::purge(self::RACE_CONNECTION);

        $race = DB::connection(self::RACE_CONNECTION);
        $race->statement("SET lock_timeout = '10s'");

        return $race;
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
