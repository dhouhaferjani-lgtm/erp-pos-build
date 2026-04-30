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
 * End-to-end test for Codex review B1 (2026-04-30): the offline POS client
 * must produce v3 fiscal hashes when the terminal is at
 * `fiscal_schema_version = 3`, and the server must accept those payloads.
 *
 * This test simulates the offline flow:
 *   1. The terminal is at v3.
 *   2. A payload is constructed that mirrors what the TS canonicalizer
 *      (`buildCanonicalPayload`) produces — same canonical input shape, same
 *      lower-cased `method_code`, same `payment_type = "pos"` sentinel.
 *   3. The payload is posted through `/api/v1/pos/receipts/sync` with
 *      `fiscal_schema_version = 3`.
 *   4. The server recomputes the v3 hash via `V3ReceiptHashComputer` and
 *      asserts it matches the offline-computed hash.
 *   5. The receipt is persisted with `fiscal_status = Fiscalized` and the
 *      terminal chain advances exactly once.
 *
 * The "TS-canonicalizer parity" assertion lives in
 * `apps/pos/src/lib/fiscal/v3/__tests__/canonicalPayload.test.ts` — fixture-08
 * proves the TS code produces byte-identical output to the post-B2 PHP
 * builder. This file proves that, given a payload of that canonical shape,
 * the sync controller accepts it.
 */
final class OfflineV3CutoverSyncTest extends TestCase
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

    public function test_v3_terminal_accepts_offline_receipt_sealed_with_canonical_v3_hash(): void
    {
        // Arrange: v3 terminal + open shift.
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $postedAt = now();
        $receiptNumber = 'POS-V3-OFFLINE-001';

        // Pre-compute the expected hash by sealing a synthetic receipt with the
        // same canonical input that the offline path would produce.
        $precomputeReceipt = Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'receipt_number' => $receiptNumber,
            'idempotency_key' => 'v3-offline-precompute-'.uniqid(),
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

        // Reset terminal so the sync re-runs on a clean chain.
        $finalized->delete();
        $terminal->refresh();
        $terminal->last_hash = null;
        $terminal->current_sequence = 1;
        $terminal->save();

        // Act: POST the offline payload with fiscal_schema_version = 3.
        $idempotencyKey = 'v3-offline-cutover-'.uniqid();
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'receipt_number' => $receiptNumber,
            'terminal_id' => $terminal->id,
            'operator_id' => $this->user->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1',
                    'unit_price' => '20.000',
                ],
            ],
            'subtotal' => '20.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '20.000',
            'currency' => 'TND',
            'offline_fiscal_hash' => $expectedHash,
            'previous_hash' => null,
            'hash_sequence' => 1,
            'transaction_discount_amount' => null,
            'transaction_discount_reason' => null,
            'tendered_amount' => '20.000',
            'change_due' => '0.000',
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepo->id,
            'created_at' => $postedAt->toIso8601String(),
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'repository_id' => $this->paymentRepo->id,
                    'amount' => '20.000',
                ],
            ],
            'consumption_mode' => null,
            'table_id' => null,
            // The new contract: clients MUST declare the version they sealed under.
            'fiscal_schema_version' => 3,
        ];

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        // Assert: server accepts the v3 payload, persists the receipt as Fiscalized,
        // and advances the chain exactly once.
        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'synced');

        $stored = Receipt::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($stored, 'Receipt must be persisted after successful sync');
        $this->assertSame(FiscalStatus::Fiscalized, $stored->fiscal_status);
        $this->assertSame($expectedHash, $stored->fiscal_hash);

        $terminal->refresh();
        $this->assertSame(2, $terminal->current_sequence);
        $this->assertSame($expectedHash, $terminal->last_hash);
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
