<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Models\Country;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\DTOs\SyncReceiptPayload;
use App\Modules\POS\Application\Services\ReceiptSyncService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Enums\SyncStatus;
use App\Modules\POS\Domain\Receipt;
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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * B5-fix audit blocker (2026-05-01) — server-side voucher redemption during
 * offline receipt sync.
 *
 * The Tauri POS production cashier flow is offline-first: every checkout
 * goes through `processAdvancedCheckout` → `createReceiptLocalFirst` →
 * `createOfflineReceipt`. The B5 commit `156ed65c` correctly wired
 * `ReceiptPaymentService::processReceiptPayments` (online admin POS path) to
 * call `VoucherRedemptionService::redeem`, but the audit found that the
 * offline → sync path NEVER lands the canonical redemption: the voucher
 * remains indefinitely re-redeemable on the canonical projection because
 * `ReceiptSyncService::syncBatch()` only writes `pos_receipt_payments` rows
 * and never invokes the redemption service.
 *
 * This test file proves the fix: when a sync payload contains a
 * `store_voucher` payment row (with the B3+B4 instrument pair), the sync
 * service MUST invoke `VoucherRedemptionService::redeem` inside the same
 * `DB::transaction()` as the receipt-payment write. A redemption failure
 * rolls back the receipt + payment + GL entry atomically.
 *
 * These tests fail on parent commit `002fc681` (no redemption call from
 * sync service) and pass after the wiring lands.
 */
final class ReceiptSyncServiceVoucherRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private PaymentMethod $cashMethod;

    private PaymentRepository $cashRepo;

    private PaymentMethod $voucherMethod;

    private PaymentRepository $voucherRepo;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_offline_sync_with_store_voucher_payment_invokes_redemption_service(): void
    {
        // Arrange: v2 terminal + open shift + a redeemable voucher.
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 2);
        $this->makeShift($terminal);

        $voucher = $this->seedVoucher($terminal, 'SV-2026-7777', '50.00');

        // Build a sync payload with a store_voucher payment that fully covers
        // the receipt total. We use the two-pass hash strategy: first sync
        // with a dummy hash, capture the server-computed hash from the error,
        // then sync again with the correct hash. The wrapping transaction
        // for the failed pass rolls back; the redemption service in pass 1
        // also rolls back.
        $payload = $this->buildVoucherPayload(
            $terminal,
            'SV-2026-7777',
            '15.000',
            'sync-voucher-redemption-'.uniqid(),
            'POS-V2-VOUCHER-REDEEM-001',
            offlineHash: str_repeat('a', 64),
        );

        /** @var ReceiptSyncService $service */
        $service = $this->app->make(ReceiptSyncService::class);

        // Pass 1: dummy hash → fails with mismatch, rolls back.
        $results = $service->syncBatch([$payload]);
        $this->assertSame(SyncStatus::Failed, $results[0]->status);
        $serverHash = $this->extractHashFromMismatchError((string) $results[0]->error);

        $terminal->refresh();
        $this->assertSame(1, $terminal->current_sequence);
        // Pass 1 must have rolled back: voucher untouched, no redemption row.
        $voucher->refresh();
        $this->assertSame(VoucherStatus::Issued, $voucher->status);
        $this->assertSame('50.00000', $voucher->current_balance);
        $this->assertDatabaseMissing('voucher_ledger', [
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Redeemed->value,
        ]);

        // Pass 2: correct hash → success. Same idempotency key — but
        // the DB::transaction rolled back in pass 1, so the receipt was not
        // persisted and the idempotency check will not short-circuit.
        $payload2 = $this->buildVoucherPayload(
            $terminal,
            'SV-2026-7777',
            '15.000',
            $payload->idempotencyKey,
            $payload->receiptNumber,
            offlineHash: $serverHash,
        );

        $results = $service->syncBatch([$payload2]);
        $this->assertSame(SyncStatus::Synced, $results[0]->status);

        // Assert: receipt payment row exists with the instrument fields.
        $receipt = Receipt::where('idempotency_key', $payload2->idempotencyKey)->firstOrFail();
        $this->assertSame(FiscalStatus::Fiscalized, $receipt->fiscal_status);
        $this->assertDatabaseHas('pos_receipt_payments', [
            'receipt_id' => $receipt->id,
            'instrument_serial' => 'SV-2026-7777',
            'payment_method_code' => 'store_voucher',
        ]);

        // Assert: voucher_ledger Redeemed row exists tied to the receipt.
        $ledger = VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Redeemed)
            ->where('receipt_id', $receipt->id)
            ->firstOrFail();
        $this->assertNotNull($ledger->gl_journal_entry_id);
        $this->assertSame($terminal->id, $ledger->terminal_id);
        $this->assertSame($this->user->id, $ledger->user_id);

        // Assert: voucher balance decremented at internal precision (5 for EUR).
        $voucher->refresh();
        $this->assertSame('35.00000', $voucher->current_balance);
        $this->assertSame(VoucherStatus::PartiallyRedeemed, $voucher->status);

        // Assert: GL JournalEntry posted with two lines (Dr VoucherLiability /
        // Cr PosTenderClearing).
        $entry = JournalEntry::query()
            ->where('id', $ledger->gl_journal_entry_id)
            ->with('lines')
            ->firstOrFail();
        $this->assertCount(2, $entry->lines);
    }

    public function test_offline_sync_with_insufficient_voucher_balance_rolls_back_receipt(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 2);
        $this->makeShift($terminal);
        $voucher = $this->seedVoucher($terminal, 'SV-LOW-001', '5.00');

        // Receipt total 15 but voucher only has 5 — redemption must throw,
        // and the wrapping DB::transaction rolls back. The sync service
        // catches the throw and reports SyncStatus::Failed.
        $payload = $this->buildVoucherPayload(
            $terminal,
            'SV-LOW-001',
            '15.000',
            'sync-low-balance-'.uniqid(),
            'POS-V2-LOW-BAL-001',
            offlineHash: str_repeat('b', 64),
        );

        /** @var ReceiptSyncService $service */
        $service = $this->app->make(ReceiptSyncService::class);

        $results = $service->syncBatch([$payload]);
        $this->assertSame(SyncStatus::Failed, $results[0]->status);

        // Receipt MUST NOT have been persisted — full transaction rollback.
        $this->assertDatabaseMissing('pos_receipts', [
            'idempotency_key' => $payload->idempotencyKey,
        ]);
        $this->assertDatabaseMissing('pos_receipt_payments', [
            'instrument_serial' => 'SV-LOW-001',
        ]);
        $this->assertDatabaseMissing('voucher_ledger', [
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Redeemed->value,
        ]);

        // Voucher untouched.
        $voucher->refresh();
        $this->assertSame('5.00000', $voucher->current_balance);
        $this->assertSame(VoucherStatus::Issued, $voucher->status);

        // Terminal chain MUST NOT have advanced.
        $terminal->refresh();
        $this->assertSame(1, $terminal->current_sequence);
        $this->assertNull($terminal->last_hash);
    }

    public function test_offline_sync_with_unknown_voucher_rolls_back_receipt(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 2);
        $this->makeShift($terminal);
        // No voucher seeded — the redemption service throws
        // VoucherInvalidStatusException for the unknown code.

        $payload = $this->buildVoucherPayload(
            $terminal,
            'SV-DOES-NOT-EXIST',
            '15.000',
            'sync-unknown-'.uniqid(),
            'POS-V2-UNKNOWN-001',
            offlineHash: str_repeat('c', 64),
        );

        /** @var ReceiptSyncService $service */
        $service = $this->app->make(ReceiptSyncService::class);

        $results = $service->syncBatch([$payload]);
        $this->assertSame(SyncStatus::Failed, $results[0]->status);

        $this->assertDatabaseMissing('pos_receipts', [
            'idempotency_key' => $payload->idempotencyKey,
        ]);
    }

    public function test_offline_sync_with_currency_mismatch_rolls_back_receipt(): void
    {
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 2);
        $this->makeShift($terminal);
        // Voucher in TND, receipt in EUR — currency mismatch must throw.
        $voucher = Voucher::factory()
            ->forTerminal($terminal)
            ->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => 'SV-WRONG-CCY',
                'currency' => 'TND',
                'initial_balance' => '50.00',
                'current_balance' => '50.00',
                'issued_by_user_id' => $this->user->id,
            ]);

        $payload = $this->buildVoucherPayload(
            $terminal,
            'SV-WRONG-CCY',
            '15.000',
            'sync-ccy-mismatch-'.uniqid(),
            'POS-V2-CCY-MM-001',
            offlineHash: str_repeat('d', 64),
        );

        /** @var ReceiptSyncService $service */
        $service = $this->app->make(ReceiptSyncService::class);

        $results = $service->syncBatch([$payload]);
        $this->assertSame(SyncStatus::Failed, $results[0]->status);

        $this->assertDatabaseMissing('pos_receipts', [
            'idempotency_key' => $payload->idempotencyKey,
        ]);
        $voucher->refresh();
        $this->assertSame('50.00000', $voucher->current_balance);
        $this->assertSame(VoucherStatus::Issued, $voucher->status);
    }

    public function test_offline_sync_idempotent_replay_does_not_double_redeem(): void
    {
        // First sync redeems; replay with same idempotency key returns
        // SyncStatus::Duplicate WITHOUT calling redeem again. The existing
        // sync idempotency guard catches this BEFORE entering the transaction.
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 2);
        $this->makeShift($terminal);
        $voucher = $this->seedVoucher($terminal, 'SV-IDEMPOTENT', '50.00');

        // Two-pass hash strategy: first call with dummy hash captures server
        // hash, then succeeds with correct hash on the next call.
        $payload = $this->buildVoucherPayload(
            $terminal,
            'SV-IDEMPOTENT',
            '15.000',
            'sync-idempotent-'.uniqid(),
            'POS-V2-IDEMPOTENT-001',
            offlineHash: str_repeat('e', 64),
        );

        /** @var ReceiptSyncService $service */
        $service = $this->app->make(ReceiptSyncService::class);
        $results = $service->syncBatch([$payload]);
        $serverHash = $this->extractHashFromMismatchError((string) $results[0]->error);

        $payload2 = $this->buildVoucherPayload(
            $terminal,
            'SV-IDEMPOTENT',
            '15.000',
            $payload->idempotencyKey,
            $payload->receiptNumber,
            offlineHash: $serverHash,
        );
        $results = $service->syncBatch([$payload2]);
        $this->assertSame(SyncStatus::Synced, $results[0]->status);

        $voucher->refresh();
        $this->assertSame('35.00000', $voucher->current_balance);

        // Replay with the same idempotency key — sync layer returns Duplicate
        // and does NOT enter the transaction (idempotency_key check is the
        // first thing syncSingleReceipt does). The voucher must NOT decrement
        // again.
        $results = $service->syncBatch([$payload2]);
        $this->assertSame(SyncStatus::Duplicate, $results[0]->status);

        $voucher->refresh();
        $this->assertSame('35.00000', $voucher->current_balance, 'Voucher must not double-redeem on replay');

        // Exactly ONE Redeemed ledger row tied to this receipt.
        $count = VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Redeemed)
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_offline_sync_cash_only_payment_does_not_invoke_redemption(): void
    {
        // Regression guard: a cash-only sync must NOT touch voucher_ledger.
        $terminal = $this->makeTerminal(fiscalSchemaVersion: 2);
        $this->makeShift($terminal);
        $voucher = $this->seedVoucher($terminal, 'SV-UNUSED', '50.00');

        $payload = $this->buildCashPayload(
            $terminal,
            '15.000',
            'sync-cash-only-'.uniqid(),
            'POS-V2-CASH-ONLY-001',
            offlineHash: str_repeat('f', 64),
        );

        /** @var ReceiptSyncService $service */
        $service = $this->app->make(ReceiptSyncService::class);

        // Two-pass to capture correct hash.
        $results = $service->syncBatch([$payload]);
        $serverHash = $this->extractHashFromMismatchError((string) $results[0]->error);

        $payload2 = $this->buildCashPayload(
            $terminal,
            '15.000',
            $payload->idempotencyKey,
            $payload->receiptNumber,
            offlineHash: $serverHash,
        );
        $results = $service->syncBatch([$payload2]);
        $this->assertSame(SyncStatus::Synced, $results[0]->status);

        // Voucher untouched, no Redeemed ledger row anywhere.
        $voucher->refresh();
        $this->assertSame('50.00000', $voucher->current_balance);
        $this->assertSame(VoucherStatus::Issued, $voucher->status);
        $this->assertDatabaseMissing('voucher_ledger', [
            'voucher_id' => $voucher->id,
            'event' => VoucherEvent::Redeemed->value,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildVoucherPayload(
        Terminal $terminal,
        string $voucherCode,
        string $amount,
        string $idempotencyKey,
        string $receiptNumber,
        string $offlineHash,
    ): SyncReceiptPayload {
        return new SyncReceiptPayload(
            idempotencyKey: $idempotencyKey,
            receiptNumber: $receiptNumber,
            terminalId: $terminal->id,
            operatorId: $this->user->id,
            lines: [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1',
                    'unit_price' => $amount,
                ],
            ],
            subtotal: $amount,
            taxAmount: '0.000',
            discountAmount: '0.000',
            total: $amount,
            currency: 'EUR',
            offlineFiscalHash: $offlineHash,
            previousHash: null,
            hashSequence: 1,
            transactionDiscountAmount: null,
            transactionDiscountReason: null,
            tenderedAmount: $amount,
            changeDue: '0.000',
            paymentMethodId: $this->voucherMethod->id,
            paymentRepositoryId: $this->voucherRepo->id,
            createdAt: now()->toIso8601String(),
            payments: [
                [
                    'payment_method_id' => $this->voucherMethod->id,
                    'repository_id' => $this->voucherRepo->id,
                    'amount' => $amount,
                    'method_code' => 'store_voucher',
                    'instrument_type' => 'store_voucher',
                    'instrument_serial' => $voucherCode,
                ],
            ],
            consumptionMode: null,
            tableId: null,
            fiscalSchemaVersion: 2,
        );
    }

    private function buildCashPayload(
        Terminal $terminal,
        string $amount,
        string $idempotencyKey,
        string $receiptNumber,
        string $offlineHash,
    ): SyncReceiptPayload {
        return new SyncReceiptPayload(
            idempotencyKey: $idempotencyKey,
            receiptNumber: $receiptNumber,
            terminalId: $terminal->id,
            operatorId: $this->user->id,
            lines: [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1',
                    'unit_price' => $amount,
                ],
            ],
            subtotal: $amount,
            taxAmount: '0.000',
            discountAmount: '0.000',
            total: $amount,
            currency: 'EUR',
            offlineFiscalHash: $offlineHash,
            previousHash: null,
            hashSequence: 1,
            transactionDiscountAmount: null,
            transactionDiscountReason: null,
            tenderedAmount: $amount,
            changeDue: '0.000',
            paymentMethodId: $this->cashMethod->id,
            paymentRepositoryId: $this->cashRepo->id,
            createdAt: now()->toIso8601String(),
            payments: [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'repository_id' => $this->cashRepo->id,
                    'amount' => $amount,
                    'method_code' => 'cash',
                ],
            ],
            consumptionMode: null,
            tableId: null,
            fiscalSchemaVersion: 2,
        );
    }

    /**
     * Extract the server-computed hash from an OfflineFiscalHashMismatchException
     * error message. Format mirrors the exception's create() factory:
     *   "...computed=<64-hex>... offline=<64-hex>..."
     */
    private function extractHashFromMismatchError(string $error): string
    {
        // The exception message contains the server-computed hash; pull it out
        // with a regex matching a 64-hex run that is NOT the dummy 'a' / 'b' /
        // etc. repeats we sent.
        if (preg_match_all('/[0-9a-f]{64}/', $error, $matches) === false) {
            $this->fail('Could not parse hash mismatch error: '.$error);
        }
        $hashes = $matches[0];
        // Filter out single-character repeats (the dummy hash sent in pass 1).
        foreach ($hashes as $hash) {
            if (! preg_match('/^([0-9a-f])\1{63}$/', $hash)) {
                return $hash;
            }
        }
        $this->fail('Could not extract server hash from: '.$error);
    }

    private function seedVoucher(Terminal $terminal, string $code, string $balance): Voucher
    {
        return Voucher::factory()
            ->forTerminal($terminal)
            ->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => $code,
                'currency' => 'EUR',
                'initial_balance' => $balance,
                'current_balance' => $balance,
                'issued_by_user_id' => $this->user->id,
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

        Country::firstOrCreate(
            ['code' => 'FR'],
            ['name' => 'France', 'currency_code' => 'EUR', 'currency_symbol' => '€'],
        );

        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
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

        // Seed the chart of accounts so VoucherRedemptionService::redeem can
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

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'cash',
        ]);
        $this->cashRepo = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash Drawer',
            'code' => 'CASH-01',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashAccount->id,
        ]);

        $this->voucherMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
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
