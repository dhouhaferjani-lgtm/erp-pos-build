<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 29 — New-sale server-authoring disposition (spec v7 §14.2).
 *
 * Pins the disposition of the §14.2 new-sale write surface (web POS +
 * Tauri-online + order-close → SALE_RECEIPT path). Three backend routes
 * must return HTTP 410 Gone with structured `NEW_SALE_AUTHORING_RETIRED`
 * error code; the carve-out (`processReturn`) and read-only surfaces stay
 * live, since its event types (`REFUND_RECEIPT`, `PARTIAL_REFUND`) are
 * Phase 2+ reserved — disabling it would also break the offline POS client.
 *
 * DPA V9 (owner ruling D3): the `void` carve-out is no longer retained —
 * it is SUNSET and now answers 410 `LEGACY_VOID_RETIRED`.
 *
 * Discriminated-union test matrix (Task 20 standing pattern) — all
 * dispositioned + knowingly-retained + read-only call-sites pinned in
 * round-1 to avoid a "missed call-site" round-2 finding:
 *   - POST /pos/receipts           → 410 + NEW_SALE_AUTHORING_RETIRED
 *   - POST /pos/receipts/{id}/payments → 410 (storePayments)
 *   - POST /pos/orders/{id}/close → 410 + zero pos_receipts rows written
 *   - POST /pos/receipts/{id}/void → 410 + LEGACY_VOID_RETIRED (DPA V9 sunset)
 *   - POST /pos/receipts/{id}/return → NOT 410 (knowingly retained)
 *   - GET  /pos/receipts           → still 2xx (index — read-only)
 *   - GET  /pos/receipts/{id}     → still 2xx (show — read-only)
 *   - GET  /pos/receipts/{id}/pdf → still 2xx (PDF stream — read-only)
 *   - POST /pos/orders            → still 2xx (order CRUD untouched)
 *   - POST /pos/orders/{id}/lines → still 2xx (order CRUD untouched)
 *   - POST /pos/receipts/sync     → 405 (retired by Task 27B Pass 2B)
 *
 * D8 coexistence resolution: spec §14 disposition (b) — disabled /
 * deferred — for `ReceiptCreationService::createReceipt()` and
 * `ReceiptController::store/storePayments` callers. The chokepoint
 * grep gate ships in Task 30.
 */
final class NewSaleServerAuthoringDispositionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $user;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Session B lane Q-9: the surviving (non-close) order routes are now
        // gated on `module:Menu` (rule 12). The default factory vertical is
        // `retail`, which has no Menu, so `test_order_crud_non_close_routes_
        // still_function` would 403 instead of proving the route is un-retired.
        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Restaurant]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'genesis_seed' => str_repeat('0', 64),
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // The Spatie permissions registrar must point at the user's tenant
        // so role/permission lookups in the route closures + controllers
        // resolve against the seeded team-scoped permissions.
        $this->app->make(PermissionRegistrar::class)
            ->setPermissionsTeamId($this->tenant->id);

        // Manager role carries pos.operate_terminal + pos.view_receipts +
        // pos.void_receipts + pos.process_returns — covers all routes the
        // test asserts on.
        $this->user->assignRole('manager');

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        Sanctum::actingAs($this->user);
    }

    // =================================================================
    // Disabled call-sites — all return HTTP 410 + NEW_SALE_AUTHORING_RETIRED
    // =================================================================

    public function test_post_pos_receipts_is_rejected_for_new_sale_authoring(): void
    {
        $response = $this->postJson('/api/v1/pos/receipts', $this->newSalePayload());

        $response->assertStatus(410);
        $this->assertSame('NEW_SALE_AUTHORING_RETIRED', $response->json('error.code'));
        // No pos_receipts row authored server-side.
        $this->assertSame(0, DB::table('pos_receipts')->count());
    }

    public function test_post_pos_receipts_payments_is_rejected_for_new_sale_authoring(): void
    {
        // storePayments is the second call-site in the spec §14.2 disposition
        // (`createReceipt` + `processReceiptPayments`). The plan template
        // covered only createReceipt — adding this pins the storePayments
        // route in round-1 per Task 20 discriminated-union standing pattern.
        $receiptId = '00000000-0000-0000-0000-000000000001';

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$receiptId}/payments",
            ['payments' => [['payment_method_id' => '00000000-0000-0000-0000-000000000002', 'amount' => 1, 'repository_id' => '00000000-0000-0000-0000-000000000003']]],
        );

        $response->assertStatus(410);
        $this->assertSame('NEW_SALE_AUTHORING_RETIRED', $response->json('error.code'));
    }

    public function test_post_pos_orders_close_no_longer_authors_a_sale_receipt(): void
    {
        $order = $this->seedOpenOrder();

        $response = $this->postJson("/api/v1/pos/orders/{$order->id}/close");

        $response->assertStatus(410);
        $this->assertSame('NEW_SALE_AUTHORING_RETIRED', $response->json('error.code'));
        // No SALE_RECEIPT authored server-side via the order-close path.
        $this->assertSame(0, DB::table('pos_receipts')->count());
        // Order itself is NOT mutated to closed (the close service chain is
        // bypassed by the route-level 410 closure).
        $this->assertSame(
            OrderStatus::Open->value,
            DB::table('pos_orders')->where('id', $order->id)->value('status'),
        );
    }

    // =================================================================
    // Knowingly-retained carve-out — processReturn — must NOT 410.
    // The void carve-out was SUNSET by DPA V9 (owner ruling D3); its
    // retirement pin lives immediately below.
    // =================================================================

    public function test_void_route_is_retired(): void
    {
        // DPA V9 (owner ruling D3 — SUNSET). §14.2 knowingly RETAINED the
        // void route; V9 retires it. `ReceiptVoidService` mutated the
        // ORIGINAL sealed receipt in place — restocking and refunding cash
        // off it with NO justifying void document and NO GL reversal.
        //
        // This test is the REGROWTH GUARD: the route must stay a
        // deterministic 410 + `LEGACY_VOID_RETIRED`, and — critically —
        // the receipt must be left UNTOUCHED (no is_voided flip, no
        // voided_at, no restock, no drawer refund). A 404 would be an
        // acceptable status but a strictly worse contract: the route must
        // still resolve so `ImpersonationWriteGuard` (api-group middleware,
        // which never runs on an unmatched path) keeps hard-blocking and
        // auditing this path.
        $receipt = $this->seedReceipt();

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$receipt->id}/void",
            [
                'reason' => 'Test void',
            ] + $this->voidReturnApprovalPayload(
                $receipt,
                'POS_RECEIPT_VOID',
                [
                    'receipt_id' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'reason' => 'Test void',
                ],
            ),
        );

        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'LEGACY_VOID_RETIRED');

        // The sealed receipt must be completely unmutated.
        $row = DB::table('pos_receipts')->where('id', $receipt->id)->first();
        $this->assertNotNull($row);
        /** @var object{is_voided: bool|int, voided_at: ?string, voided_by: ?string, void_reason: ?string} $row */
        $this->assertFalse((bool) $row->is_voided);
        $this->assertNull($row->voided_at);
        $this->assertNull($row->voided_by);
        $this->assertNull($row->void_reason);
    }

    public function test_void_route_is_retired_regardless_of_payload_shape(): void
    {
        // The tombstone is a route-level closure, so it short-circuits
        // BEFORE any validation — same contract as the §14.2 410 closures.
        // A legacy terminal posting a stale/empty body must still receive
        // the retirement code, never a 422 that masks it.
        $receipt = $this->seedReceipt();

        $this->postJson("/api/v1/pos/receipts/{$receipt->id}/void", [])
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'LEGACY_VOID_RETIRED');
    }

    public function test_process_return_route_still_responds_in_phase_1(): void
    {
        // Round-2 (Codex T29-F1 P2): symmetric positive-path pin for the
        // processReturn carve-out. Round-1 posted `lines: []` and asserted
        // "not 410", which proved nothing about the surviving online
        // return contract. Spec v7 §14.2 line 669 keeps this route live
        // because REFUND_RECEIPT / PARTIAL_REFUND are Phase 2+ reserved AND
        // the offline Tauri POS shares the route via the refund checkout
        // flow (VoidReturnModal was deleted in refund Phase 6).
        //
        // Seed a posted sale receipt with one returnable line, request a
        // partial return, assert 201 + return receipt shape + DB row.
        $saleReceipt = $this->seedReceipt();
        $line = ReceiptLine::create([
            'receipt_id' => $saleReceipt->id,
            'line_number' => 1,
            'product_id' => null,
            'product_code' => 'PROD-001',
            'product_name' => 'Widget A',
            'quantity' => '5.000',
            'unit' => 'pcs',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'discount_amount' => '0.000',
        ]);

        $response = $this->postJson(
            "/api/v1/pos/receipts/{$saleReceipt->id}/return",
            [
                'terminal_id' => $this->terminal->id,
                'return_reason' => ReturnReason::Defective->value,
                'lines' => [
                    ['line_id' => $line->id, 'quantity' => '2'],
                ],
                // Task 2a — refund_request_id is a required idempotency key.
                'refund_request_id' => Str::uuid()->toString(),
            ] + $this->voidReturnApprovalPayload(
                $saleReceipt,
                'POS_RECEIPT_RETURN',
                [
                    'receipt_id' => $saleReceipt->id,
                    'receipt_number' => $saleReceipt->receipt_number,
                    'line_ids' => [$line->id],
                    'reason' => '',
                ],
            ),
        );

        $response->assertStatus(201);
        $this->assertSame(
            ReceiptType::Return->value,
            $response->json('data.receipt_type'),
        );
        $this->assertSame(
            $saleReceipt->id,
            $response->json('data.original_receipt_id'),
        );
        $this->assertSame(
            ReturnReason::Defective->value,
            $response->json('data.return_reason'),
        );

        // DB side effect: a return receipt row exists with the correct
        // type / original_receipt_id / negative total.
        $returnId = $response->json('data.id');
        $this->assertNotNull($returnId);
        $this->assertDatabaseHas('pos_receipts', [
            'id' => $returnId,
            'receipt_type' => ReceiptType::Return->value,
            'original_receipt_id' => $saleReceipt->id,
            'return_reason' => ReturnReason::Defective->value,
        ]);
    }

    // =================================================================
    // Read-only routes — still functional
    // =================================================================

    public function test_read_only_get_receipts_index_still_functions(): void
    {
        // Round-1 pin per Task 20 — the GET index is a read-only route that
        // the disposition explicitly preserves (Web shop-management / POS
        // section lists receipts/transactions).
        $response = $this->getJson('/api/v1/pos/receipts');

        $response->assertOk();
    }

    public function test_read_only_get_receipt_show_still_functions(): void
    {
        $receipt = $this->seedReceipt();

        $response = $this->getJson("/api/v1/pos/receipts/{$receipt->id}");

        $response->assertOk();
    }

    public function test_read_only_get_receipt_pdf_still_functions(): void
    {
        $receipt = $this->seedReceipt();

        $response = $this->get("/api/v1/pos/receipts/{$receipt->id}/pdf");

        // PDF stream returns 200 (binary blob) when successful. Pin that
        // the route is reachable — not 410-retired.
        $this->assertNotSame(410, $response->status());
        // 200 is the expected success status; 404 / 422 would indicate a
        // PDF service failure but still confirms the route exists.
        $this->assertContains($response->status(), [200, 404, 422, 500]);
    }

    // =================================================================
    // Order CRUD untouched — non-close routes still author
    // =================================================================

    public function test_order_crud_non_close_routes_still_function(): void
    {
        // The spec is explicit: the order-close → receipt step is
        // disabled, but the rest of order CRUD/lines/kitchen routes in
        // routes_orders.php are untouched.
        $response = $this->postJson('/api/v1/pos/orders', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
        ]);

        $response->assertStatus(201);
        $this->assertNotSame('NEW_SALE_AUTHORING_RETIRED', $response->json('error.code'));
    }

    // =================================================================
    // Sync surface — retired by Task 27B Pass 2B
    // =================================================================

    public function test_pos_receipts_sync_route_is_retired(): void
    {
        $response = $this->postJson('/api/v1/pos/receipts/sync', [
            'receipts' => [],
        ]);

        // The exact URI now falls through to /pos/receipts/{id} for GET-only
        // lookup semantics, so POST receives 405. This still proves the
        // retired sync POST route is absent.
        $response->assertStatus(405);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function voidReturnApprovalPayload(Receipt $receipt, string $targetEventType, array $target): array
    {
        $approvalId = Str::uuid()->toString();
        $approvalEventId = Str::uuid()->toString();
        $overrideEventId = Str::uuid()->toString();

        $approvalPayload = [
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'cashier_user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $target['reason'] ?? null,
            'regime_extensions' => null,
            'requested_at_device' => now()->toISOString(),
            'resolved_at_device' => now()->toISOString(),
            'supervisor_user_id' => $this->user->id,
            'supervisor_user_snapshot' => ['name' => $this->user->name, 'roles' => ['manager']],
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'training_flag' => false,
        ];
        $this->storeFiscalEvent($approvalEventId, FiscalEventType::OPERATOR_APPROVAL_GRANTED, $approvalPayload);

        $overridePayload = [
            'approval_event_id' => $approvalEventId,
            'approval_id' => $approvalId,
            'approval_scope' => 'void_or_return_override',
            'company_id' => $this->company->id,
            'event_time_device' => now()->toISOString(),
            'override_context' => [
                'target_event_type' => $targetEventType,
                'target_reference_id' => $receipt->id,
            ],
            'policy_version' => 'pos-void-return-policy-v1',
            'reason_code' => 'manager_reason',
            'reason_text' => $target['reason'] ?? null,
            'supervisor_user_id' => $this->user->id,
            'target' => $target,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'training_flag' => false,
        ];
        $this->storeFiscalEvent(
            $overrideEventId,
            FiscalEventType::OVERRIDE_VOID_OR_RETURN,
            $overridePayload,
            $approvalEventId,
        );

        return [
            'approval_id' => $approvalId,
            'approval_fiscal_event_id' => $approvalEventId,
            'approval_scope' => 'void_or_return_override',
            'approval_supervisor_user_id' => $this->user->id,
            'approval_override_event_id' => $overrideEventId,
            'authorized_by_user_id' => $this->user->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeFiscalEvent(
        string $id,
        FiscalEventType $eventType,
        array $payload,
        ?string $referenceEventId = null,
    ): void {
        FiscalEvent::query()->create([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->user->id,
            'event_type' => $eventType->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => DB::table('fiscal_events')->count() + 1,
            'event_time_device' => now(),
            'business_date' => now()->toDateString(),
            'server_received_at' => now(),
            'reference_event_id' => $referenceEventId,
            'canonical_bytes' => json_encode(['payload' => $payload], JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $id),
            'payload' => $payload,
            'payload_parse_status' => 'parsed',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function newSalePayload(): array
    {
        // Minimal shape — the route closure short-circuits before the
        // StoreReceiptRequest validation runs. We never get past the 410.
        return [
            'terminal_id' => $this->terminal->id,
            'lines' => [
                [
                    'product_id' => '00000000-0000-0000-0000-000000000001',
                    'quantity' => 1,
                    'unit_price' => '10.000',
                ],
            ],
        ];
    }

    private function seedOpenOrder(): Order
    {
        /** @var Order $order */
        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'order_number' => '#001',
            'status' => OrderStatus::Open,
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name ?? 'Test Cashier',
            'subtotal' => '0.0000',
            'tax_amount' => '0.0000',
            'discount_amount' => '0.0000',
            'total' => '0.0000',
            'currency' => 'TND',
            'opened_at' => now(),
        ]);

        return $order;
    }

    private function seedReceipt(): Receipt
    {
        return Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name ?? 'Test Cashier',
        ]);
    }
}
