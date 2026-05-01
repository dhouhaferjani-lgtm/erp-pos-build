<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Models\Country;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptFinalizationService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * End-to-end test for Codex review B1 (2026-04-30) and B3 (2026-04-30):
 *
 *   B1: the offline POS client must produce v3 fiscal hashes when the
 *       terminal is at `fiscal_schema_version = 3`, and the server must
 *       accept those payloads.
 *   B3: voucher-bearing v3 receipts must round-trip through sync with the
 *       full `instrument_type` and `instrument_serial` payload preserved,
 *       so the server-recomputed hash reproduces the offline-sealed hash.
 *
 * The cash-only test below is the smoke regression. The voucher-bearing
 * test (`test_v3_terminal_accepts_offline_voucher_payment_with_instrument_fields_intact`)
 * is the production-path proof for B3 — it fails unless the entire
 * `offline → sync` chain preserves instrument fields. Without it, the
 * earlier B2 unit test would still pass while production silently dropped
 * the voucher serial from `pos_receipt_payments` (the bug Codex flagged).
 *
 * Discipline note: these tests precompute the offline hash with PHP server
 * code rather than the TS canonicalizer. The TS-canonicalizer parity proof
 * lives in `apps/pos/src/lib/fiscal/v3/__tests__/canonicalPayload.test.ts`
 * (fixture-01 cash-only and fixture-08 store-voucher-binding); together
 * they bracket the production path:
 *   - TS produces the same canonical bytes (and hash) as PHP for both
 *     cash-only and voucher-bearing inputs (canonicalPayload.test.ts).
 *   - The sync controller accepts a payload of that canonical shape
 *     (this file).
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

    private PaymentMethod $voucherMethod;

    private PaymentRepository $voucherRepo;

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
                    // B3-followup audit (Finding 2, 2026-05-01): method_code is
                    // REQUIRED on the sync wire (was nullable). The audit
                    // promoted the rule and removed the writer's fallback.
                    'method_code' => $this->paymentMethod->code,
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

    /**
     * Codex review B3 (2026-04-30): a voucher-bearing offline v3 receipt must
     * round-trip through sync with `instrument_type` and `instrument_serial`
     * intact on the persisted `pos_receipt_payments` row, AND the server's
     * recomputed v3 fiscal hash must match the offline-sealed hash.
     *
     * Without B3's wiring, the POS would seal the v3 hash with the voucher
     * serial included (B2 fixture-08 proves the canonical builder binds it),
     * but the sync DTO + writer would strip the field, so the server would
     * recompute against `instrument_serial = null` and reject the receipt as
     * a chain-break / hash mismatch.
     *
     * The test case is a 50/50 split tender: cash + store_voucher, with the
     * voucher carrying serial `SV-2026-0042`.
     */
    public function test_v3_terminal_accepts_offline_voucher_payment_with_instrument_fields_intact(): void
    {
        // Arrange: v3 terminal + open shift.
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $postedAt = now();
        $receiptNumber = 'POS-V3-VOUCHER-001';
        $voucherSerial = 'SV-2026-0042';

        // B5-fix audit blocker (2026-05-01): seed a real voucher so
        // ReceiptSyncService can invoke VoucherRedemptionService::redeem
        // during sync. The new offline-then-sync contract is that the sync
        // service writes both the receipt-payment row AND the canonical
        // redemption — the voucher MUST exist on the server-side projection
        // for redemption to land.
        $voucher = Voucher::factory()
            ->forTerminal($terminal)
            ->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => $voucherSerial,
                'currency' => 'TND',
                'initial_balance' => '50.000',
                'current_balance' => '50.000',
                'issued_by_user_id' => $this->user->id,
            ]);

        // Pre-compute the expected hash by sealing a synthetic receipt with the
        // same canonical input that the offline+sync path would produce. The
        // synthetic receipt carries TWO payment rows (cash + store_voucher with
        // an instrument_serial), exactly mirroring what the TS createOfflineReceipt
        // and receiptToPayload would emit for a 50/50 split.
        $precomputeReceipt = Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $terminal->company_id,
            'location_id' => $terminal->location_id,
            'terminal_id' => $terminal->id,
            'receipt_number' => $receiptNumber,
            'idempotency_key' => 'v3-voucher-precompute-'.uniqid(),
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
            'payment_method_code' => $this->paymentMethod->code,
            'amount' => '10.000',
        ]);
        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $precomputeReceipt->id,
            'payment_method_id' => $this->voucherMethod->id,
            'payment_type' => $this->voucherMethod->code,
            'payment_method_code' => $this->voucherMethod->code,
            'amount' => '10.000',
            'instrument_type' => PaymentInstrumentKind::StoreVoucher,
            'instrument_serial' => $voucherSerial,
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

        // Act: POST the offline payload with fiscal_schema_version = 3 and
        // both payments wired through with method_code, instrument_type, and
        // instrument_serial — exactly what the TS sync layer must send post-B3.
        $idempotencyKey = 'v3-voucher-cutover-'.uniqid();
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
                    'amount' => '10.000',
                    'method_code' => $this->paymentMethod->code,
                    'instrument_type' => null,
                    'instrument_serial' => null,
                ],
                [
                    'payment_method_id' => $this->voucherMethod->id,
                    'repository_id' => $this->voucherRepo->id,
                    'amount' => '10.000',
                    'method_code' => $this->voucherMethod->code,
                    'instrument_type' => 'store_voucher',
                    'instrument_serial' => $voucherSerial,
                ],
            ],
            'consumption_mode' => null,
            'table_id' => null,
            'fiscal_schema_version' => 3,
        ];

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        // Assert: server accepts the payload, persists the receipt as Fiscalized,
        // server-recomputed hash matches the offline hash, AND the voucher row
        // round-trips with instrument_type = store_voucher and instrument_serial = SV-2026-0042.
        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'synced');

        $stored = Receipt::where('idempotency_key', $idempotencyKey)->firstOrFail();
        $this->assertSame(FiscalStatus::Fiscalized, $stored->fiscal_status);
        $this->assertSame(
            $expectedHash,
            $stored->fiscal_hash,
            'Server-recomputed v3 hash must match offline-sealed hash for voucher-bearing receipt',
        );

        $voucherPayment = $stored->payments
            ->firstWhere('payment_method_id', $this->voucherMethod->id);
        $this->assertNotNull($voucherPayment, 'Voucher payment row must persist');
        $this->assertSame(
            PaymentInstrumentKind::StoreVoucher,
            $voucherPayment->instrument_type,
        );
        $this->assertSame($voucherSerial, $voucherPayment->instrument_serial);
        $this->assertSame($this->voucherMethod->code, $voucherPayment->payment_method_code);

        $terminal->refresh();
        $this->assertSame(2, $terminal->current_sequence);
        $this->assertSame($expectedHash, $terminal->last_hash);

        // B5-fix audit blocker (2026-05-01): the canonical voucher_ledger
        // Redeemed row MUST exist tied to the synced receipt — proving the
        // offline-then-sync redemption lands on the canonical projection.
        // This is the load-bearing fiscal/accounting assertion the audit
        // required and the previous shape of this test did not exercise.
        $ledger = VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Redeemed)
            ->where('receipt_id', $stored->id)
            ->firstOrFail();
        $this->assertNotNull($ledger->gl_journal_entry_id);

        // Voucher balance must have decremented at internal precision (5 for TND).
        $voucher->refresh();
        $this->assertSame('40.00000', $voucher->current_balance);
        $this->assertSame(VoucherStatus::PartiallyRedeemed, $voucher->status);
    }

    /**
     * Codex review B4 (2026-04-30) — end-to-end fiscal proof on the sync wire.
     *
     * The offline+sync test above proves that a voucher-bearing receipt
     * round-trips when the instrument pair is present. This test proves the
     * inverse: a stale offline client (or a forged payload) that ships
     * `method_code: store_voucher` with both instrument fields null MUST be
     * rejected by the request validator with 422 — not silently sealed into
     * the v3 chain with a null voucher serial.
     *
     * The positive case at line 226 must continue to pass; this test must
     * fail on parent commit (where the value-conditional rule does not exist)
     * and pass on HEAD.
     */
    public function test_v3_terminal_rejects_store_voucher_payment_with_null_instrument_fields(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 3);
        $this->makeShift($terminal);

        $payload = [
            'idempotency_key' => 'v3-voucher-null-fields-'.uniqid(),
            'receipt_number' => 'POS-V3-NULLINSTR-001',
            'terminal_id' => $terminal->id,
            'operator_id' => $this->user->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1',
                    'unit_price' => '10.000',
                ],
            ],
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '10.000',
            'currency' => 'TND',
            'offline_fiscal_hash' => str_repeat('a', 64),
            'previous_hash' => null,
            'hash_sequence' => 1,
            'transaction_discount_amount' => null,
            'transaction_discount_reason' => null,
            'tendered_amount' => '10.000',
            'change_due' => '0.000',
            'payment_method_id' => $this->voucherMethod->id,
            'payment_repository_id' => $this->voucherRepo->id,
            'created_at' => now()->toIso8601String(),
            'payments' => [
                [
                    'payment_method_id' => $this->voucherMethod->id,
                    'repository_id' => $this->voucherRepo->id,
                    'amount' => '10.000',
                    'method_code' => 'store_voucher',
                    // Both instrument fields deliberately omitted — this is the
                    // exact stale-client shape Codex flagged.
                    'instrument_type' => null,
                    'instrument_serial' => null,
                ],
            ],
            'consumption_mode' => null,
            'table_id' => null,
            'fiscal_schema_version' => 3,
        ];

        $response = $this->postJson('/api/v1/pos/receipts/sync', $payload);

        // The validator must intercept BEFORE the writer ever runs. 422 with
        // both keys named in the response body so cause is unambiguous.
        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('instrument_type', $body);
        $this->assertStringContainsString('instrument_serial', $body);
        $this->assertStringContainsString('store_voucher', $body);

        // The terminal chain MUST NOT have advanced — no receipt persisted.
        $terminal->refresh();
        $this->assertSame(1, $terminal->current_sequence);
        $this->assertNull($terminal->last_hash);

        $this->assertDatabaseMissing('pos_receipts', [
            'idempotency_key' => $payload['idempotency_key'],
        ]);
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

        // Country row required by ChartOfAccountsService::seedForCompany.
        Country::firstOrCreate(
            ['code' => 'TN'],
            ['name' => 'Tunisia', 'currency_code' => 'TND', 'currency_symbol' => 'د.ت'],
        );

        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
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

        // Seed chart of accounts so VoucherRedemptionService::redeem (now
        // invoked by ReceiptSyncService for store_voucher payments) can
        // resolve VoucherLiability + PosTenderClearing by purpose.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $cashAccount = Account::where([
            'company_id' => $this->company->id,
            'system_purpose' => SystemAccountPurpose::Cash->value,
        ])->firstOrFail();

        $voucherClearing = Account::where([
            'company_id' => $this->company->id,
            'system_purpose' => SystemAccountPurpose::PosTenderClearing->value,
        ])->firstOrFail();

        $this->paymentMethod = PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);

        $this->paymentRepo = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash Drawer',
            'code' => 'CASH-01',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashAccount->id,
        ]);

        $this->voucherMethod = PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Voucher',
            'code' => 'store_voucher',
        ]);

        $this->voucherRepo = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Voucher Clearing Repo',
            'code' => 'VOUCHER-01',
            'type' => RepositoryType::Virtual->value,
            'gl_account_id' => $voucherClearing->id,
        ]);
    }
}
