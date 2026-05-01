<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Validation-focused tests for POST /api/v1/pos/receipts/sync.
 *
 * B3-followup audit (Finding 2, 2026-05-01): `method_code` was nullable on
 * the sync wire with a server-side fallback to a live `payment_methods.code`
 * lookup. The fallback is the wrong contract:
 *
 *   - `method_code` is the snapshot the POS hashed against. The server MUST
 *     use the same string when recomputing the v3 hash, or the chain breaks.
 *   - The fallback only ever fires for pre-B3 cash-only queued rows. Post-B3
 *     clients always send `method_code`. So the field SHOULD be required on
 *     the wire — anything else hides drift.
 *
 * This file locks the contract: a sync payload missing `method_code` per
 * payment row MUST be rejected with 422.
 */
final class SyncReceiptsRequestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    private Product $product;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $paymentRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    public function test_sync_payload_missing_method_code_returns_422(): void
    {
        $payload = $this->buildReceiptPayload();

        // Drop method_code from the (single) payment row to simulate a stale
        // pre-B3 client. Post-B3 clients always carry this field.
        unset($payload['payments'][0]['method_code']);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(422);
        // Assert the validation error is keyed precisely so a future regression
        // (e.g. someone re-relaxing the rule to nullable) fails on the path
        // string itself, not on a generic 422 catch-all.
        $body = (string) $response->getContent();
        $this->assertStringContainsString('method_code', $body);
    }

    public function test_sync_payload_with_empty_string_method_code_returns_422(): void
    {
        $payload = $this->buildReceiptPayload();

        // Required + string + max:64 must reject empty string. Without this,
        // an empty method_code would still hit the writer's old fallback path
        // and silently substitute a payment_methods.code lookup.
        $payload['payments'][0]['method_code'] = '';

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('method_code', $body);
    }

    public function test_sync_payload_with_method_code_succeeds(): void
    {
        // Positive control: a payload that DOES carry method_code per payment
        // row passes validation. (The actual sync may still fail downstream on
        // hash mismatch — that's not what this test is about; we just want a
        // 200 status and the validation gate green.)
        $payload = $this->buildReceiptPayload();
        $payload['payments'][0]['method_code'] = $this->paymentMethod->code;

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(200);
    }

    /**
     * Codex review B4 (2026-04-30): on the sync wire `method_code` is the
     * client snapshot — there is no FK lookup in validation. So the
     * value-conditional rule must inspect the snapshot string itself: if
     * (lowercased) it is one of the instrument-bearing codes per the
     * PaymentInstrumentKind enum, both `instrument_type` and
     * `instrument_serial` MUST be present and non-empty.
     *
     * Without this guard, a stale offline client (or a forged sync payload)
     * could ship `method_code: store_voucher` with both instrument fields
     * null and the v3 hash recomputation would faithfully bind a null
     * voucher serial — same fiscal-integrity hole as B2/B3.
     */
    public function test_sync_payload_store_voucher_with_null_instrument_fields_returns_422(): void
    {
        $payload = $this->buildReceiptPayload();
        $payload['payments'][0]['method_code'] = 'store_voucher';
        // Both instrument fields deliberately omitted — must be rejected.

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('instrument_type', $body);
        $this->assertStringContainsString('instrument_serial', $body);
        // Surface the offending method code so cause is unambiguous.
        $this->assertStringContainsString('store_voucher', $body);
    }

    public function test_sync_payload_store_voucher_uppercase_with_null_instrument_fields_returns_422(): void
    {
        // Normalization: even an uppercased method_code (from a stale client
        // that ignored backend-shaped lowercase migration) must be detected.
        $payload = $this->buildReceiptPayload();
        $payload['payments'][0]['method_code'] = 'STORE_VOUCHER';

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('instrument_type', $body);
        $this->assertStringContainsString('instrument_serial', $body);
    }

    public function test_sync_payload_restaurant_voucher_with_null_instrument_fields_returns_422(): void
    {
        $payload = $this->buildReceiptPayload();
        $payload['payments'][0]['method_code'] = 'restaurant_voucher';

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('restaurant_voucher', $body);
    }

    public function test_sync_payload_gift_card_with_null_instrument_fields_returns_422(): void
    {
        $payload = $this->buildReceiptPayload();
        $payload['payments'][0]['method_code'] = 'gift_card';

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('gift_card', $body);
    }

    public function test_sync_payload_store_voucher_with_empty_instrument_fields_returns_422(): void
    {
        // Empty strings must be treated as null for B4 enforcement, otherwise
        // a client could submit `""` to bypass the value-conditional rule.
        $payload = $this->buildReceiptPayload();
        $payload['payments'][0]['method_code'] = 'store_voucher';
        $payload['payments'][0]['instrument_type'] = '';
        $payload['payments'][0]['instrument_serial'] = '';

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('instrument_type', $body);
        $this->assertStringContainsString('instrument_serial', $body);
    }

    public function test_sync_payload_cash_with_null_instrument_fields_still_passes_validation(): void
    {
        // Positive control for B4: non-instrument-bearing method codes (here
        // CASH from the seeder) must continue to land with null instrument
        // fields — the rule is value-conditional, not blanket-required.
        $payload = $this->buildReceiptPayload();
        $payload['payments'][0]['method_code'] = $this->paymentMethod->code; // CASH

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        // 200 means the validation gate didn't bite. Downstream sync may still
        // fail on hash mismatch (not under test here) but B4's rule must not
        // touch a cash row.
        $response->assertStatus(200);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildReceiptPayload(array $overrides = []): array
    {
        $defaults = [
            'idempotency_key' => 'syncreq-'.uniqid(),
            'receipt_number' => 'POS01-2026-00000001',
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->user->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1',
                    'unit_price' => '10.00',
                ],
            ],
            'subtotal' => '10.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'total' => '10.00',
            'currency' => 'TND',
            'offline_fiscal_hash' => str_repeat('a', 64),
            'previous_hash' => $this->terminal->last_hash ?? '',
            'hash_sequence' => 1,
            'transaction_discount_amount' => null,
            'transaction_discount_reason' => null,
            'tendered_amount' => '10.00',
            'change_due' => '0.00',
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepo->id,
            'created_at' => now()->toIso8601String(),
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'repository_id' => $this->paymentRepo->id,
                    'amount' => '10.00',
                    'method_code' => $this->paymentMethod->code,
                ],
            ],
            'consumption_mode' => null,
            'table_id' => null,
            'fiscal_schema_version' => 2,
        ];

        return array_merge($defaults, $overrides);
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
        Permission::findOrCreate('pos.manage_shifts', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');
        $this->user->givePermissionTo('pos.manage_shifts');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'last_hash' => null,
            'current_sequence' => 1,
            'current_year' => (int) now()->format('Y'),
        ]);

        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
            'opening_cash' => '100.00',
        ]);

        $this->product = Product::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);

        $this->paymentMethod = PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);

        $this->paymentRepo = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }
}
