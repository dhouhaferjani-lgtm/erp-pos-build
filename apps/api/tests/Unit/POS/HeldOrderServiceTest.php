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
use App\Modules\POS\Domain\Exceptions\HeldOrderDiscardRefusedException;
use App\Modules\POS\Domain\Exceptions\HeldOrderRecallConflictException;
use App\Modules\POS\Domain\HeldOrder;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertNotNull($heldOrder->expires_at);
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

    /**
     * Q-8 fix round — an already-recalled basket IS the production lost-race
     * observation (on PostgreSQL the loser's locking SELECT re-reads exactly
     * this row version under EvalPlanQual), so the guard raises the typed
     * conflict, not a bare RuntimeException. See the status -> code table on
     * `HeldOrderService::recallOrder()`.
     */
    public function test_recall_already_recalled_order_throws_the_typed_conflict(): void
    {
        $heldOrder = $this->createHeldOrder([
            'status' => HeldOrderStatus::Recalled,
            'recalled_at' => now(),
        ]);

        $this->expectException(HeldOrderRecallConflictException::class);

        $this->service->recallOrder($heldOrder->id);
    }

    /**
     * The other half of the split: a lapsed TTL was consumed by nobody, so it
     * must NOT be the typed conflict (the controller maps it to 422
     * `RECALL_FAILED`, whose remedy is not "refresh and retry").
     */
    public function test_recall_expired_order_throws_a_plain_runtime_exception(): void
    {
        $heldOrder = $this->createHeldOrder([
            'expires_at' => now()->subMinutes(10),
        ]);

        try {
            $this->service->recallOrder($heldOrder->id);
            $this->fail('Expected the expired basket to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(HeldOrderRecallConflictException::class, $e);
            $this->assertStringContainsString('expired', $e->getMessage());
        }
    }

    // =========================================================================
    // Q-8 — held-order recall hardening (POS-till sub-report MEDIUM)
    // =========================================================================

    /**
     * Q-8 (a) — the check-then-set window.
     *
     * Pre-fix `recallOrder()` was `findOrFail()` (no lock, no transaction) →
     * `canBeRecalled()` → `update(['status' => Recalled])`. Two tills racing on
     * the same parked basket both pass the guard on a stale read and both get
     * the full `cart_snapshot` back, so the basket is rung up (and stock
     * decremented) twice.
     *
     * This case pins the SECOND backstop, which is the ONLY defence on SQLite
     * (where `FOR UPDATE` is a no-op): the `retrieved` model event fires
     * immediately after the service's SELECT and the hook flips the row to
     * `recalled` from underneath it, on the SAME connection and transaction.
     * The in-memory model the service holds is now stale, `canBeRecalled()`
     * still returns true, and the only thing that can save the basket is the
     * conditional `UPDATE ... WHERE status = 'held'` asserting exactly one
     * affected row — which must raise the same typed conflict the guard does.
     *
     * That interleaving is NOT how PostgreSQL produces a lost race: there the
     * loser's `SELECT ... FOR UPDATE` blocks and re-reads the committed
     * `recalled` row version (EvalPlanQual), so the guard fires first. The
     * production shape is staged against a real second connection in
     * `HeldOrderRecallContractTest::test_a_lost_recall_race_against_a_second_connection_is_a_409_conflict`.
     */
    public function test_recall_refuses_when_the_row_is_flipped_after_the_read(): void
    {
        $heldOrder = $this->createHeldOrder();

        $flipped = false;
        HeldOrder::retrieved(function (HeldOrder $model) use ($heldOrder, &$flipped): void {
            if ($flipped || $model->id !== $heldOrder->id) {
                return;
            }
            $flipped = true;

            // Simulates till B winning the race between our SELECT and our UPDATE.
            DB::table('pos_held_orders')
                ->where('id', $heldOrder->id)
                ->update([
                    'status' => HeldOrderStatus::Recalled->value,
                    'recalled_at' => now(),
                ]);
        });

        try {
            $this->expectException(HeldOrderRecallConflictException::class);

            $this->service->recallOrder($heldOrder->id);
        } finally {
            HeldOrder::flushEventListeners();
        }
    }

    /**
     * Q-8 (b) — recall must honour the terminal the caller claims.
     *
     * `listHeldOrders()` has always been terminal-scoped, so a basket parked on
     * till A is never *shown* on till B; the recall lookup was tenant+company
     * only, so till B could still take it by id. When the caller supplies the
     * terminal it is operating, a mismatch must miss the scope entirely (404,
     * same shape as a cross-tenant miss) rather than succeed.
     */
    public function test_recall_with_a_mismatched_terminal_id_is_scoped_out(): void
    {
        $heldOrder = $this->createHeldOrder();
        $otherTerminal = $this->createSecondTerminal();

        $this->expectException(ModelNotFoundException::class);

        $this->service->recallOrder($heldOrder->id, $otherTerminal->id);
    }

    /**
     * Q-8 (b) — happy path: hold → list → recall on the SAME terminal works,
     * and the basket leaves the list exactly once.
     */
    public function test_recall_on_the_holding_terminal_succeeds_and_consumes_the_basket_once(): void
    {
        $heldOrder = $this->service->holdOrder(
            terminalId: $this->terminal->id,
            shiftId: $this->shift->id,
            cashierId: $this->user->id,
            cartSnapshot: $this->makeCartSnapshot(),
            label: 'Table 5',
        );

        $this->assertCount(1, $this->service->listHeldOrders($this->terminal->id));

        $recalled = $this->service->recallOrder($heldOrder->id, $this->terminal->id);

        $this->assertEquals(HeldOrderStatus::Recalled, $recalled->status);
        $this->assertNotNull($recalled->recalled_at);
        $this->assertCount(0, $this->service->listHeldOrders($this->terminal->id));

        // Second till (or a double-tap) gets the typed conflict (409), not a
        // second snapshot.
        $this->expectException(HeldOrderRecallConflictException::class);
        $this->service->recallOrder($heldOrder->id, $this->terminal->id);
    }

    /**
     * Q-8 (d) — a recalled order is the only trace of what was parked and rung
     * up. Discarding it must be refused, not silently destroy the evidence.
     */
    public function test_discard_of_a_recalled_order_is_refused(): void
    {
        $heldOrder = $this->createHeldOrder([
            'status' => HeldOrderStatus::Recalled,
            'recalled_at' => now(),
        ]);

        try {
            $this->service->discardOrder($heldOrder->id, $this->user->id);
            $this->fail('Expected HeldOrderDiscardRefusedException.');
        } catch (HeldOrderDiscardRefusedException) {
            // expected
        }

        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $heldOrder->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * Q-8 (d) — discard soft-deletes and records the actor.
     */
    public function test_discard_soft_deletes_and_records_the_actor(): void
    {
        $heldOrder = $this->createHeldOrder();

        $this->service->discardOrder($heldOrder->id, $this->user->id);

        $this->assertSoftDeleted('pos_held_orders', ['id' => $heldOrder->id]);

        $row = DB::table('pos_held_orders')->where('id', $heldOrder->id)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame($this->user->id, $row->discarded_by);

        // Soft-deleted baskets disappear from the till's list and from recall.
        $this->assertCount(0, $this->service->listHeldOrders($this->terminal->id));
        $this->expectException(ModelNotFoundException::class);
        $this->service->recallOrder($heldOrder->id, $this->terminal->id);
    }

    public function test_discard_order_soft_deletes_and_hides_the_row(): void
    {
        $heldOrder = $this->createHeldOrder();

        $this->service->discardOrder($heldOrder->id, $this->user->id);

        // The row survives (audit trail) but is invisible to every model query.
        $this->assertSoftDeleted('pos_held_orders', ['id' => $heldOrder->id]);
        $this->assertNull(HeldOrder::find($heldOrder->id));
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
        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertEquals('Active', $first->label);
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

        // A terminal may have only one OPEN shift (pos_shifts_one_open_per_terminal
        // partial unique index on PostgreSQL). $this->shift is already open, so
        // this second shift is closed; held orders still reference it by id.
        // Closed shifts require closed_at + closed_by (pos_shifts_closed_logic).
        $otherShift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 2,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Closed,
            'opened_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
            'closed_by' => $this->user->id,
        ]);

        $this->createHeldOrder([
            'label' => 'Shift 2',
            'shift_id' => $otherShift->id,
        ]);

        $result = $this->service->listHeldOrders($this->terminal->id, $this->shift->id);

        $this->assertCount(1, $result);
        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertEquals('Shift 1', $first->label);
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

        $count = $this->service->expireOrders($this->tenant->id, $this->company->id);

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

    /**
     * Q-8 — the expiry sweep must not resurrect a discarded basket.
     *
     * `discardOrder()` now soft-deletes, so `expireOrders()` (an Eloquent mass
     * UPDATE, therefore under the SoftDeletingScope) must skip the row: it is
     * neither counted nor flipped to `expired`, and its `deleted_at` survives.
     * A hard-`DELETE`d row could not have been touched either; a soft-deleted
     * one is still physically present, which is exactly the new hazard.
     */
    public function test_expire_orders_skips_soft_deleted_orders(): void
    {
        $discarded = $this->createHeldOrder([
            'label' => 'Discarded then past its TTL',
            'expires_at' => now()->subMinutes(10),
        ]);
        $this->createHeldOrder([
            'label' => 'Live and past its TTL',
            'expires_at' => now()->subMinutes(10),
        ]);

        $this->service->discardOrder($discarded->id, $this->user->id);

        $count = $this->service->expireOrders($this->tenant->id, $this->company->id);

        $this->assertSame(1, $count, 'Only the live basket may be expired.');

        $row = DB::table('pos_held_orders')->where('id', $discarded->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(HeldOrderStatus::Held->value, $row->status, 'A discarded basket must not be re-expired.');
        $this->assertNotNull($row->deleted_at);

        $this->assertDatabaseHas('pos_held_orders', [
            'label' => 'Live and past its TTL',
            'status' => HeldOrderStatus::Expired->value,
        ]);
    }

    public function test_expire_orders_returns_zero_when_none_expired(): void
    {
        $this->createHeldOrder(['expires_at' => now()->addHours(4)]);

        $count = $this->service->expireOrders($this->tenant->id, $this->company->id);

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

    /**
     * A second till in the same company, used by the terminal-scoping tests.
     */
    private function createSecondTerminal(): Terminal
    {
        $location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        return Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);
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
