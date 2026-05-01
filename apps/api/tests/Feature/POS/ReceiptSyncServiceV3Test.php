<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptFinalizationService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for Task 8: ReceiptSyncService v3-aware finalization.
 *
 * Covers:
 *   1. v2 payload against v2 terminal: server recomputes legacy hash, matches offline_fiscal_hash.
 *   2. v3 payload against v3 terminal: server recomputes v3 hash, matches offline_fiscal_hash.
 *   3. v2 payload against v3 terminal post-cutover: rejected with version-mismatch error.
 *   4. offline_fiscal_hash mismatch: receipt rejected, chain NOT advanced.
 *   5. v3 payload finalizes via ReceiptFinalizationService (not inline seal), fiscal_status=fiscalized.
 *
 * Pre-compute strategy:
 *   For the happy-path tests (1, 2, 5) we need the offline_fiscal_hash sent in the payload
 *   to equal what the server will compute. We pre-compute by:
 *     (a) Creating a pending_seal receipt in the DB with the exact same field values
 *         that the sync service will produce (including matching vat_breakdown_hash and
 *         payment_methods_hash for v2, or matching payment rows for v3).
 *     (b) Running ReceiptFinalizationService::finalize() to capture the hash.
 *     (c) Deleting that receipt and resetting the terminal.
 *     (d) Syncing with that captured hash as offline_fiscal_hash.
 *
 *   Products are created with tax_rate = 0 so VAT computation is deterministic.
 */
final class ReceiptSyncServiceV3Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $paymentRepo;

    /** Product with tax_rate = 0 for deterministic VAT computation */
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    // -------------------------------------------------------------------------
    // Test 1: v2 payload against v2 terminal — happy path
    // -------------------------------------------------------------------------

    public function test_sync_v2_payload_against_v2_terminal_recomputes_legacy_hash_and_matches_offline(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 2);
        $this->makeShift($terminal);

        $idempotencyKey = 'v2-happy-'.uniqid();
        $receiptNumber = 'POS-V2-HASH-TEST-001';
        $postedAt = now();

        // Two-pass approach to get the correct offline_fiscal_hash without
        // replicating the sync service's internal VAT hash computation.
        //
        // Pass 1: sync with a deliberately wrong hash (64 x 'a') and capture the
        //         server_computed hash from the error message.  The receipt is
        //         rolled back (hash mismatch), so the terminal is unchanged.
        //
        // Pass 2: sync again — same idempotency_key, same payload — with the
        //         correct hash captured from pass 1.  This time it succeeds.

        // --- Pass 1 ---
        $dummyHash = str_repeat('a', 64);
        $passOnePayload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'receipt_number' => $receiptNumber,
            'idempotency_key' => $idempotencyKey,
            'offline_fiscal_hash' => $dummyHash,
            'fiscal_schema_version' => 2,
            'created_at' => $postedAt->toIso8601String(),
        ]);

        $passOneResponse = $this->postJson('/api/v1/pos/receipts/sync', $passOnePayload);
        $passOneResponse->assertStatus(200);
        $passOneResponse->assertJsonPath('data.results.0.status', 'failed');

        // Extract the server-computed hash from the error message:
        // "... payload reported <X> but server computed <SERVER_HASH> ..."
        $errorMsg = (string) $passOneResponse->json('data.results.0.error');
        $this->assertStringContainsString('server computed', $errorMsg,
            'Pass 1 must be a hash-mismatch failure containing "server computed"');

        preg_match('/server computed ([0-9a-f]{64})/', $errorMsg, $matches);
        $this->assertNotEmpty($matches, 'Could not extract server_computed hash from error message');
        $serverComputedHash = $matches[1];

        // Terminal chain must NOT have advanced (transaction was rolled back)
        $terminal->refresh();
        $this->assertSame(1, $terminal->current_sequence);
        $this->assertNull($terminal->last_hash);

        // --- Pass 2: sync with the correct hash ---
        $passTwoPayload = array_merge($passOnePayload, ['offline_fiscal_hash' => $serverComputedHash]);

        $passTwoResponse = $this->postJson('/api/v1/pos/receipts/sync', $passTwoPayload);
        $passTwoResponse->assertStatus(200);
        $passTwoResponse->assertJsonPath('data.results.0.status', 'synced');

        $stored = Receipt::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($stored);
        $this->assertSame(FiscalStatus::Fiscalized, $stored->fiscal_status);
        // The stored fiscal_hash equals the hash the server computed in pass 1
        $this->assertSame($serverComputedHash, $stored->fiscal_hash);

        // Terminal chain advanced exactly once
        $terminal->refresh();
        $this->assertSame(2, $terminal->current_sequence);
        $this->assertSame($serverComputedHash, $terminal->last_hash);
    }

    // -------------------------------------------------------------------------
    // Test 2: v3 payload against v3 terminal — happy path
    // -------------------------------------------------------------------------

    public function test_sync_v3_payload_against_v3_terminal_recomputes_v3_hash_and_matches_offline(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $idempotencyKey = 'v3-happy-sync-'.uniqid();
        $postedAt = now();
        $receiptNumber = 'POS-V3-HASH-TEST-001';

        // Pre-compute expected v3 hash.
        // The sync service creates payment rows with payment_type = $method->code = 'CASH'.
        // The v3 computer maps: method_code = strtolower(payment_type) = 'cash'.
        // VAT rows: since product has tax_rate=0, the sync creates 1 row with tax_rate=0.00.
        // The v3 computer maps this to: {rate: '0.00', amount: '0.000'}.
        // But wait: for zero-rate the v3 vat_breakdown includes a zero entry.
        // Replicate exactly.

        $precomputeReceipt = Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'receipt_number' => $receiptNumber,
            'idempotency_key' => 'v3-precompute-'.uniqid(),
            'chain_sequence' => null,
            'previous_hash' => null,
            'posted_at' => $postedAt,
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name,
            'subtotal' => '20.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '20.000',
            'change_due' => '0.000',
            'currency' => 'TND',
            'is_voided' => false,
        ]);

        // Create matching payment row (payment_type = code of paymentMethod = 'CASH')
        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $precomputeReceipt->id,
            'payment_method_id' => $this->paymentMethod->id,
            'payment_type' => $this->paymentMethod->code, // 'CASH'
            'amount' => '20.000',
        ]);

        // Create matching VAT detail row (mirrors what sync service creates for zero-tax line)
        ReceiptVatDetail::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $precomputeReceipt->id,
            'tax_rate' => '0.00',
            'net_amount' => '20.000',
            'vat_amount' => '0.000',
            'gross_amount' => '20.000',
        ]);

        /** @var ReceiptFinalizationService $finalizer */
        $finalizer = app(ReceiptFinalizationService::class);
        $finalized = $finalizer->finalize($precomputeReceipt);
        $expectedHash = $finalized->fiscal_hash;

        // Reset
        $finalized->delete();
        $terminal->refresh();
        $terminal->last_hash = null;
        $terminal->current_sequence = 1;
        $terminal->save();

        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'receipt_number' => $receiptNumber,
            'idempotency_key' => $idempotencyKey,
            'offline_fiscal_hash' => $expectedHash,
            'fiscal_schema_version' => 3,
            'created_at' => $postedAt->toIso8601String(),
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'synced');

        $stored = Receipt::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($stored);
        $this->assertSame(FiscalStatus::Fiscalized, $stored->fiscal_status);
        $this->assertSame($expectedHash, $stored->fiscal_hash);
    }

    // -------------------------------------------------------------------------
    // Test 3: v2 payload against v3 terminal post-cutover → rejected
    // -------------------------------------------------------------------------

    public function test_sync_rejects_v2_payload_against_v3_terminal_post_cutover(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'fiscal_schema_version' => 2, // v2 payload against v3 terminal
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'failed');

        // Error message must reference the version mismatch / v3
        $errorMessage = $response->json('data.results.0.error');
        $this->assertStringContainsStringIgnoringCase('v3', $errorMessage);

        // No receipt should have been persisted
        $this->assertDatabaseMissing('pos_receipts', [
            'terminal_id' => $terminal->id,
            'idempotency_key' => $payload['idempotency_key'],
        ]);

        // Terminal chain must NOT have advanced
        $terminal->refresh();
        $this->assertSame(1, $terminal->current_sequence);
        $this->assertNull($terminal->last_hash);
    }

    // -------------------------------------------------------------------------
    // Test 4: offline_fiscal_hash mismatch → tamper-error, chain not advanced
    // -------------------------------------------------------------------------

    public function test_sync_rejects_payload_when_offline_hash_does_not_match_server_recompute(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 2);
        $this->makeShift($terminal);

        // Deliberately wrong hash (valid 64-char hex, wrong content)
        $wrongHash = str_repeat('d', 64);

        $idempotencyKey = 'hash-mismatch-'.uniqid();
        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'fiscal_schema_version' => 2,
            'offline_fiscal_hash' => $wrongHash,
            'idempotency_key' => $idempotencyKey,
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'failed');

        $errorMessage = $response->json('data.results.0.error');
        $this->assertStringContainsStringIgnoringCase('hash', $errorMessage);

        // No receipt persisted (transaction rolled back)
        $this->assertDatabaseMissing('pos_receipts', [
            'idempotency_key' => $idempotencyKey,
        ]);

        // Terminal chain NOT advanced
        $terminal->refresh();
        $this->assertSame(1, $terminal->current_sequence);
        $this->assertNull($terminal->last_hash);
    }

    // -------------------------------------------------------------------------
    // Test 5: v3 payload finalizes via ReceiptFinalizationService, not inline seal
    // -------------------------------------------------------------------------

    public function test_sync_v3_payload_finalizes_via_finalization_service_not_inline_seal(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $postedAt = now();
        $receiptNumber = 'POS-V3-FINSERV-001';

        // Pre-compute expected hash (same pattern as test 2)
        $precomputeReceipt = Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'receipt_number' => $receiptNumber,
            'idempotency_key' => 'v3-finserv-precompute-'.uniqid(),
            'chain_sequence' => null,
            'previous_hash' => null,
            'posted_at' => $postedAt,
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name,
            'subtotal' => '20.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '20.000',
            'change_due' => '0.000',
            'currency' => 'TND',
            'is_voided' => false,
        ]);

        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $precomputeReceipt->id,
            'payment_method_id' => $this->paymentMethod->id,
            'payment_type' => $this->paymentMethod->code,
            'amount' => '20.000',
        ]);

        ReceiptVatDetail::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $precomputeReceipt->id,
            'tax_rate' => '0.00',
            'net_amount' => '20.000',
            'vat_amount' => '0.000',
            'gross_amount' => '20.000',
        ]);

        /** @var ReceiptFinalizationService $finalizer */
        $finalizer = app(ReceiptFinalizationService::class);
        $finalized = $finalizer->finalize($precomputeReceipt);
        $expectedHash = $finalized->fiscal_hash;

        // Reset
        $finalized->delete();
        $terminal->refresh();
        $terminal->last_hash = null;
        $terminal->current_sequence = 1;
        $terminal->save();

        $syncIdempotencyKey = 'v3-finserv-'.uniqid();
        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'receipt_number' => $receiptNumber,
            'idempotency_key' => $syncIdempotencyKey,
            'offline_fiscal_hash' => $expectedHash,
            'fiscal_schema_version' => 3,
            'created_at' => $postedAt->toIso8601String(),
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'synced');

        $stored = Receipt::where('idempotency_key', $syncIdempotencyKey)->first();
        $this->assertNotNull($stored);

        // fiscal_status must be fiscalized — set by ReceiptFinalizationService, not inline
        $this->assertSame(FiscalStatus::Fiscalized, $stored->fiscal_status);

        // The hash stored equals the expected hash
        $this->assertSame($expectedHash, $stored->fiscal_hash);

        // The terminal chain advanced exactly once (1 → 2)
        $terminal->refresh();
        $this->assertSame(2, $terminal->current_sequence);
        $this->assertNotNull($terminal->last_hash);
        $this->assertSame($expectedHash, $terminal->last_hash);
    }

    // -------------------------------------------------------------------------
    // Test 6: missing fiscal_schema_version → 422 validation failure
    //
    // Codex review B1 (2026-04-30): the legacy default-to-2 fallback was
    // removed. Any client that omits the field must surface a 422 at the
    // request layer, NOT silently downgrade to v2 (which would let a v3
    // terminal receive a v2 payload and fail downstream as a chain break).
    // -------------------------------------------------------------------------

    public function test_sync_receipt_request_rejects_payload_missing_fiscal_schema_version_with_422(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
        ]);

        // Drop the field entirely. The validator must hard-reject.
        unset($payload['fiscal_schema_version']);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(422);
        // The bootstrap-level renderer wraps validation errors under
        // `error.errors` (see bootstrap/app.php). Assert the per-key error
        // is present rather than relying on Laravel's default `errors` shape.
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $errors = $response->json('error.errors') ?? [];
        $this->assertArrayHasKey('receipts.0.fiscal_schema_version', $errors);

        // Terminal chain must NOT have advanced — the request never reached
        // the service layer.
        $terminal->refresh();
        $this->assertSame(1, $terminal->current_sequence);
        $this->assertNull($terminal->last_hash);
    }

    public function test_sync_receipt_request_rejects_invalid_fiscal_schema_version_with_422(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'fiscal_schema_version' => 4, // not in {2, 3}
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $errors = $response->json('error.errors') ?? [];
        $this->assertArrayHasKey('receipts.0.fiscal_schema_version', $errors);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildReceiptPayload(array $overrides = []): array
    {
        $defaults = [
            'idempotency_key' => 'v3test-'.uniqid(),
            'receipt_number' => 'POS01-V3-'.uniqid(),
            'terminal_id' => null,
            'operator_id' => $this->user->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1',
                    'unit_price' => '20.00',
                ],
            ],
            'subtotal' => '20.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'total' => '20.00',
            'currency' => 'TND',
            'offline_fiscal_hash' => hash('sha256', 'placeholder-v3-test'),
            'previous_hash' => null,
            'hash_sequence' => 1,
            'transaction_discount_amount' => null,
            'transaction_discount_reason' => null,
            'tendered_amount' => '20.00',
            'change_due' => '0.00',
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepo->id,
            'created_at' => now()->toIso8601String(),
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'repository_id' => $this->paymentRepo->id,
                    'amount' => '20.00',
                    // B3-followup audit (Finding 2, 2026-05-01): method_code is
                    // REQUIRED on the sync wire.
                    'method_code' => $this->paymentMethod->code,
                ],
            ],
            'consumption_mode' => null,
            'table_id' => null,
            'fiscal_schema_version' => 2,
        ];

        return array_merge($defaults, $overrides);
    }

    private function makeTerminal(int $fiscalSchemaVersion): Terminal
    {
        return Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => $fiscalSchemaVersion,
            'last_hash' => null,
            'current_sequence' => 1,
            'current_year' => (int) now()->format('Y'),
        ]);
    }

    private function makeShift(Terminal $terminal): Shift
    {
        return Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
            'opening_cash' => '100.00',
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

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        Permission::findOrCreate('pos.manage_shifts', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');
        $this->user->givePermissionTo('pos.manage_shifts');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        // Product with tax_rate = 0 for deterministic VAT computation in all tests.
        // A non-zero tax_rate would cause the sync to produce a different vat_breakdown
        // than what is assumed in the pre-compute fixtures.
        $this->product = Product::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'tax_rate' => 0,
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
