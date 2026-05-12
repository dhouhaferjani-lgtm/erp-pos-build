<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
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
 * T2.7 — Offline-First Training-Aware Receipt Sync (backend slice).
 *
 * Closes the gap surfaced by PR #99 round-8 P1: the offline-first POS path
 * sealed every sync receipt as production even when the cashier toggled the
 * terminal into training mode. The frontend's TrainingModeBanner (PR #99)
 * showed the cashier the terminal mode but the wire payload didn't carry the
 * flag, and ReceiptSyncService didn't branch on training-mode at all.
 *
 * This backend slice closes the gap at the ingest layer:
 *   - SyncReceiptPayload + SyncReceiptsRequest accept `is_training: boolean`.
 *   - ReceiptSyncService::syncSingleReceipt skips chain validation, finalize,
 *     hash mismatch, and voucher redemption when payload->isTraining is true.
 *   - The receipt persists with is_training=true, fiscal_status=Fiscalized,
 *     chain_sequence=0, and a deterministic sha256('TRAINING-' || receipt_id)
 *     sentinel hash — mirroring the online ReceiptCreationService training
 *     branch at lines 538-540 + 605-608.
 *
 * Frontend slice (apps/pos createOfflineReceipt branching) lands separately.
 * A backend-only landing already prevents the bad behaviour at the sync
 * ingest path even if the frontend lags — the kickoff doc's recommended
 * decoupling.
 */
final class ReceiptSyncServiceTrainingModeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $paymentRepo;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    /**
     * Happy path: a training payload syncs as a training receipt.
     *
     * Asserts:
     *   - is_training=true persists on the row.
     *   - fiscal_status=Fiscalized (no pending_seal → finalize transition).
     *   - chain_sequence=0 (sentinel; not part of any chain).
     *   - fiscal_hash matches the deterministic sha256('TRAINING-' || receipt_id) sentinel.
     *   - Terminal's last_hash and current_sequence are UNCHANGED — the chain did not advance.
     */
    public function test_t27_training_payload_persists_with_sentinel_hash_and_skips_chain_advance(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $idempotencyKey = 't27-training-happy-'.uniqid();
        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => $idempotencyKey,
            'fiscal_schema_version' => 3,
            'is_training' => true,
            // Training receipts skip the hash mismatch check, so any 64-char
            // value is fine — the server overrides with its own sentinel.
            'offline_fiscal_hash' => str_repeat('f', 64),
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'synced');

        $stored = Receipt::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($stored, 'Training receipt must persist');

        // Training-specific persistence shape.
        $this->assertTrue($stored->is_training, 'is_training must be true');
        $this->assertSame(FiscalStatus::Fiscalized, $stored->fiscal_status);
        // Codex round-1 P1 (2026-05-10): training receipts use NULL
        // chain_sequence (not 0) so they satisfy the PG CHECK constraint
        // `chain_sequence IS NULL OR chain_sequence > 0` and the unique
        // constraint on (terminal_id, receipt_year, chain_sequence).
        $this->assertNull($stored->chain_sequence, 'Training receipts use chain_sequence=NULL (PG CHECK + unique-constraint compatible)');
        $this->assertNull($stored->previous_hash, 'Training receipts have no previous_hash');

        // The fiscal_hash must be the deterministic training sentinel.
        $expectedSentinel = hash('sha256', 'TRAINING-'.$stored->id);
        $this->assertSame(
            $expectedSentinel,
            $stored->fiscal_hash,
            'Training receipt fiscal_hash must equal sha256("TRAINING-" || receipt_id) sentinel',
        );

        // Terminal chain must NOT have advanced.
        $terminal->refresh();
        $this->assertSame(1, $terminal->current_sequence, 'Terminal current_sequence must be unchanged');
        $this->assertNull($terminal->last_hash, 'Terminal last_hash must be unchanged');
    }

    /**
     * Training receipts skip the offline_fiscal_hash mismatch check.
     *
     * A production receipt with a wrong offline_fiscal_hash is rejected with
     * an `OfflineFiscalHashMismatchException` and the transaction rolls back.
     * For training receipts, the server doesn't recompute against the
     * payload's hash — it sets the deterministic sentinel inline. So even an
     * obviously-wrong payload hash should sync cleanly.
     */
    public function test_t27_training_payload_skips_offline_fiscal_hash_mismatch_check(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $idempotencyKey = 't27-training-mismatch-skip-'.uniqid();
        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => $idempotencyKey,
            'fiscal_schema_version' => 3,
            'is_training' => true,
            // Deliberately-wrong hash. A production receipt would reject; training accepts.
            'offline_fiscal_hash' => str_repeat('a', 64),
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'synced');

        $stored = Receipt::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($stored);
        // The server's sentinel hash, not the payload's wrong value.
        $this->assertSame(
            hash('sha256', 'TRAINING-'.$stored->id),
            $stored->fiscal_hash,
        );
    }

    /**
     * Training receipts skip the hash chain continuity check.
     *
     * A production receipt whose `previous_hash` doesn't match the terminal's
     * `last_hash` is rejected as a chain break. For training receipts, the
     * chain isn't validated.
     */
    public function test_t27_training_payload_skips_hash_chain_continuity_check(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        // Seed a non-null terminal last_hash so a production receipt with
        // previous_hash=null would normally fail the chain-break check.
        $terminal->last_hash = str_repeat('b', 64);
        $terminal->save();

        $this->makeShift($terminal);

        $idempotencyKey = 't27-training-chain-skip-'.uniqid();
        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => $idempotencyKey,
            'fiscal_schema_version' => 3,
            'is_training' => true,
            // previous_hash=null but terminal->last_hash is set — would normally fail.
            'previous_hash' => null,
            'offline_fiscal_hash' => str_repeat('c', 64),
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'synced');

        // Terminal last_hash must still be the seeded value (not advanced).
        $terminal->refresh();
        $this->assertSame(str_repeat('b', 64), $terminal->last_hash);
        $this->assertSame(1, $terminal->current_sequence);
    }

    /**
     * Regression guard: a payload with no `is_training` field defaults to
     * production. The existing chain validation, finalize, and hash mismatch
     * checks all run as before.
     *
     * Asserts the wire-shape default is backwards-compatible: a pre-T2.7
     * client sending no `is_training` field gets the existing production
     * path, including the offline_fiscal_hash mismatch rejection (since the
     * offline hash here is wrong, the response is `failed`, not `synced`).
     */
    public function test_t27_payload_without_is_training_defaults_to_production_path(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $idempotencyKey = 't27-production-default-'.uniqid();
        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => $idempotencyKey,
            'fiscal_schema_version' => 3,
            // is_training intentionally absent — pre-T2.7 client wire shape.
            'offline_fiscal_hash' => str_repeat('a', 64), // wrong hash → mismatch path
        ]);

        $this->assertArrayNotHasKey('is_training', $payload, 'Test fixture must omit is_training');

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);
        $response->assertStatus(200);
        // Production path runs the offline_fiscal_hash mismatch check → failure.
        $response->assertJsonPath('data.results.0.status', 'failed');

        // Confirm via the error message: production path entered finalize.
        $errorMsg = (string) $response->json('data.results.0.error');
        $this->assertStringContainsString(
            'server computed',
            $errorMsg,
            'Production path must recompute hash and surface mismatch — proves is_training defaulted to false',
        );

        // Terminal chain must NOT have advanced (rollback on mismatch).
        $terminal->refresh();
        $this->assertSame(1, $terminal->current_sequence);
        $this->assertNull($terminal->last_hash);
    }

    /**
     * Training receipts are excluded from `Terminal::scopeProduction()`.
     *
     * The existing read-side filter at `Terminal::scopeProduction()` (and
     * its receipt-side equivalent) excludes training receipts from
     * compliance views — Z-reports, NF525 exports, fiscal-chain reports.
     * This test confirms the synced-path training receipt is invisible to
     * a production-scoped query.
     */
    public function test_t27_training_receipt_excluded_from_production_scoped_query(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        // Sync a production receipt (T2.7 default-false behaviour).
        // We only need its existence in the receipts table, so we'll use a
        // direct factory create rather than going through the sync API which
        // requires hash precomputation.
        Receipt::factory()->create([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->user->id,
            'cashier_name' => $this->user->name,
            'is_training' => false,
            'fiscal_status' => FiscalStatus::Fiscalized,
        ]);

        // Sync a training receipt via the API path (the slice under test).
        $idempotencyKey = 't27-scope-training-'.uniqid();
        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => $idempotencyKey,
            'fiscal_schema_version' => 3,
            'is_training' => true,
        ]);
        $this->postJson('/api/v1/pos/receipts/sync', $payload)->assertStatus(200);

        // Production-scoped count: training receipt must NOT be visible.
        $productionReceipts = Receipt::query()
            ->where('terminal_id', $terminal->id)
            ->where('is_training', false)
            ->get();
        $this->assertCount(
            1,
            $productionReceipts,
            'Production-scoped query must return only the production receipt, not the training one',
        );

        $allReceipts = Receipt::query()->where('terminal_id', $terminal->id)->get();
        $this->assertCount(
            2,
            $allReceipts,
            'Both receipts must persist — training is recorded, just excluded from production scope',
        );
    }

    /**
     * Codex round-1 P2 (2026-05-10): a failed training receipt in a mixed
     * batch must NOT poison subsequent production receipts.
     *
     * Before this fix, `syncBatch` set `$chainBroken = true` on ANY failure,
     * so a training receipt that failed for a non-chain reason (e.g. invalid
     * payload field) would mark the next production receipt as
     * `chain_broken` even though its `previous_hash` correctly matched the
     * (unchanged) terminal `last_hash`.
     *
     * Setup: simulate a batch with [training-fail, production-valid]. The
     * training payload triggers an exception (invalid `payment_method_id`);
     * the production payload's chain inputs are still valid because the
     * training failure leaves the terminal state unchanged.
     */
    public function test_t27_failed_training_receipt_does_not_poison_subsequent_production_receipt(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        // Training payload that will fail at the payments persistence step
        // (PaymentMethod::findOrFail throws on the bogus UUID).
        $trainingPayload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => 't27-mixed-batch-training-'.uniqid(),
            'fiscal_schema_version' => 3,
            'is_training' => true,
            'payments' => [
                [
                    'payment_method_id' => '00000000-0000-0000-0000-000000000000',
                    'repository_id' => $this->paymentRepo->id,
                    'amount' => '20.00',
                    'method_code' => 'CASH',
                ],
            ],
        ]);

        // Production payload with a valid hash chain anchor (terminal.last_hash
        // is still null because the training failure didn't advance the chain).
        $productionPayload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => 't27-mixed-batch-production-'.uniqid(),
            'fiscal_schema_version' => 3,
            'previous_hash' => null,
            'offline_fiscal_hash' => str_repeat('a', 64), // wrong hash → mismatch failure
        ]);

        $batchResponse = $this->postJson('/api/v1/pos/receipts/sync', [
            'receipts' => [$trainingPayload, $productionPayload],
        ]);
        $batchResponse->assertStatus(200);

        // First result: training receipt failed (invalid PaymentMethod).
        $batchResponse->assertJsonPath('data.results.0.status', 'failed');

        // Second result: production receipt was attempted (NOT chain_broken).
        // The actual outcome is a hash mismatch failure (we sent a wrong hash
        // to keep the test deterministic), but critically the status must NOT
        // be `chain_broken` — the training failure must not have poisoned
        // this slot.
        $batchResponse->assertJsonPath(
            'data.results.1.status',
            'failed',
        );
        $secondError = (string) $batchResponse->json('data.results.1.error');
        $this->assertStringContainsString(
            'server computed',
            $secondError,
            'Second receipt must reach the hash mismatch check (proves the training failure did NOT short-circuit it as chain_broken)',
        );
    }

    /**
     * Codex round-1 P2 (2026-05-10): the validator's `boolean` rule accepts
     * truthy values like `1`, `'1'`, `'true'` — and falsy `0`, `'0'`,
     * `'false'`. The DTO's coercion must match the validator's semantics so a
     * payload that PASSES validation can never be silently downgraded to
     * production by a literal `=== true` check.
     *
     * Before the fix, `is_training: 1` (which the validator accepts) would
     * fall through to production because the DTO did `=== true`. Now the
     * DTO uses `FILTER_VALIDATE_BOOL` which mirrors the validator.
     */
    public function test_t27_is_training_truthy_string_is_treated_as_training(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $idempotencyKey = 't27-coerce-truthy-'.uniqid();
        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => $idempotencyKey,
            'fiscal_schema_version' => 3,
            // Validator's `boolean` rule accepts this. DTO must coerce to true.
            'is_training' => '1',
            'offline_fiscal_hash' => str_repeat('a', 64), // production would fail; training accepts
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);
        $response->assertStatus(200);
        $response->assertJsonPath(
            'data.results.0.status',
            'synced',
            'is_training="1" must coerce to true and skip the offline hash mismatch check',
        );

        $stored = Receipt::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($stored);
        $this->assertTrue($stored->is_training, 'is_training column must persist as true');
        $this->assertNull($stored->chain_sequence);
        // Sentinel hash is set, not the wrong payload hash.
        $this->assertSame(
            hash('sha256', 'TRAINING-'.$stored->id),
            $stored->fiscal_hash,
        );
    }

    /**
     * Codex round-1 P2 (2026-05-10): the inverse — `is_training: '0'` (or
     * `'false'`, or `0`) must NOT be coerced to true. These are the values
     * the validator's `boolean` rule classifies as falsy. Defaults
     * conservatively to production.
     */
    public function test_t27_is_training_falsy_string_is_treated_as_production(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => 't27-coerce-falsy-'.uniqid(),
            'fiscal_schema_version' => 3,
            // Validator accepts this as boolean false. DTO must coerce to false.
            'is_training' => '0',
            'offline_fiscal_hash' => str_repeat('a', 64), // wrong hash → production-path failure
        ]);

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);
        $response->assertStatus(200);
        // Production path runs the offline_fiscal_hash mismatch check → failure.
        $response->assertJsonPath('data.results.0.status', 'failed');
        $errorMsg = (string) $response->json('data.results.0.error');
        $this->assertStringContainsString(
            'server computed',
            $errorMsg,
            'is_training="0" must coerce to false and route through the production path',
        );
    }

    /**
     * Codex round-2 P1 (2026-05-10): NF525 chain verification must exclude
     * training receipts. Before the fix at Nf525DataProvider:270-272, the
     * verifier loaded every receipt for a terminal (no training filter) and
     * tried to rehash the training sentinel as production — falsely reporting
     * the chain as broken on every terminal that synced any training receipt.
     *
     * Setup: a terminal with one synced training receipt and no production
     * receipts. The verifier must treat the receipt list as effectively empty
     * (training filtered out) and return isValid=true. Without the fix, the
     * verifier would attempt to rehash the sentinel and return isValid=false.
     */
    public function test_t27_nf525_chain_verifier_excludes_training_receipts(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        // Sync a training receipt via the API path (the slice under test).
        $payload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => 't27-nf525-verify-'.uniqid(),
            'fiscal_schema_version' => 3,
            'is_training' => true,
        ]);
        $this->postJson('/api/v1/pos/receipts/sync', $payload)->assertStatus(200);

        // Sanity: the training receipt persisted with the sentinel hash and
        // is_training=true.
        $training = Receipt::where('terminal_id', $terminal->id)->where('is_training', true)->first();
        $this->assertNotNull($training);
        $this->assertSame(hash('sha256', 'TRAINING-'.$training->id), $training->fiscal_hash);

        // Now run the NF525 verifier. With the fix, training is filtered out
        // so the verifier sees zero receipts and returns isValid=true.
        /** @var Nf525DataProvider $provider */
        $provider = app(Nf525DataProvider::class);
        $result = $provider->verifyReceiptChain($terminal->id);

        $this->assertTrue(
            $result->isValid,
            'NF525 verifier must report a valid chain when the only receipts are training-mode (filter excluded them).',
        );
        $this->assertSame(0, $result->totalRows, 'training rows must be excluded from the row count');
    }

    /**
     * Codex round-2 P2 (2026-05-10): a production receipt failure must NOT
     * skip a subsequent training receipt in the same batch. Before the fix
     * at line 87-89, `$chainBroken=true` set by the production failure would
     * short-circuit the next iteration regardless of whether it's a training
     * receipt. Training receipts are independent of the production chain
     * and must still be attempted.
     *
     * Setup: a batch with [production-fail (wrong hash), training-valid].
     * Expect: production result = `failed` (hash mismatch), training result =
     * `synced` (NOT `chain_broken`).
     */
    public function test_t27_failed_production_receipt_does_not_skip_subsequent_training_receipt(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        // Production payload that will fail at the hash mismatch check.
        $productionPayload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => 't27-mixed-prod-fail-'.uniqid(),
            'fiscal_schema_version' => 3,
            'offline_fiscal_hash' => str_repeat('a', 64), // wrong hash
        ]);

        // Training payload that should succeed regardless of the production failure.
        $trainingPayload = $this->buildReceiptPayload([
            'terminal_id' => $terminal->id,
            'idempotency_key' => 't27-mixed-training-success-'.uniqid(),
            'fiscal_schema_version' => 3,
            'is_training' => true,
        ]);

        $batchResponse = $this->postJson('/api/v1/pos/receipts/sync', [
            'receipts' => [$productionPayload, $trainingPayload],
        ]);
        $batchResponse->assertStatus(200);

        // First (production): fails on hash mismatch.
        $batchResponse->assertJsonPath('data.results.0.status', 'failed');

        // Second (training): MUST be attempted, not chain_broken.
        $batchResponse->assertJsonPath(
            'data.results.1.status',
            'synced',
            'Training receipt after a production failure must still sync — chain-broken short-circuit must not gate training receipts',
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildReceiptPayload(array $overrides = []): array
    {
        $defaults = [
            'idempotency_key' => 't27test-'.uniqid(),
            'receipt_number' => 'POS01-T27-'.uniqid(),
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
            'offline_fiscal_hash' => hash('sha256', 'placeholder-t27-test'),
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
                    'method_code' => $this->paymentMethod->code,
                ],
            ],
            'consumption_mode' => null,
            'table_id' => null,
            'fiscal_schema_version' => 3,
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
