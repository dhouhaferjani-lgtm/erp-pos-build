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
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptPaymentService;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Exceptions\VoucherInsufficientBalanceException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInvalidStatusException;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Codex review B5 (2026-05-01) — online voucher redemption wiring.
 *
 * Before B5: ReceiptPaymentService::processReceiptPayments accepted a
 * store_voucher payment row, persisted it (with B3 instrument fields) and
 * sealed the receipt — but NEVER called VoucherRedemptionService::redeem.
 * The voucher's current_balance / status / GL liability all remained
 * untouched. The same voucher was indefinitely re-redeemable.
 *
 * After B5: a store_voucher payment in the same DB::transaction() also
 * runs the canonical redemption — voucher_ledger Redeemed row, voucher
 * balance decrement, status transition, and the Dr VoucherLiability /
 * Cr PosTenderClearing GL journal. A redemption failure (insufficient
 * balance, expired, etc.) bubbles a typed exception that the bootstrap
 * exception handlers map to HTTP 422 with a stable error.code.
 *
 * These tests fail on parent commit 5b65cd06 (no redemption call) and
 * pass after the wiring lands.
 */
final class ReceiptPaymentServiceVoucherRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $cashier;

    private PaymentMethod $voucherMethod;

    private PaymentRepository $voucherRepo;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.000',
        ]);

        // Seed full chart of accounts so VoucherRedemptionService::redeem can
        // resolve VoucherLiability + PosTenderClearing by purpose.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $voucherClearing = Account::where([
            'company_id' => $this->company->id,
            'system_purpose' => SystemAccountPurpose::PosTenderClearing->value,
        ])->firstOrFail();

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
            'currency' => 'EUR',
        ]);

        $this->actingAs($this->cashier);
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_voucher_redemption_writes_ledger_row_and_decrements_balance(): void
    {
        $voucher = $this->seedVoucher('SV-2026-0099', '50.00');
        $receipt = $this->seedPendingSealReceipt('15.000');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [[
                'amount' => '15.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                'instrument_type' => 'store_voucher',
                'instrument_serial' => 'SV-2026-0099',
            ]],
        );

        // (a) The receipt payment row exists with instrument fields (B3 + B5
        // wiring still intact).
        $this->assertDatabaseHas('pos_receipt_payments', [
            'receipt_id' => $receipt->id,
            'instrument_serial' => 'SV-2026-0099',
        ]);

        // (b) A Redeemed voucher_ledger row exists tied to this receipt.
        $ledger = VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->where('event', 'redeemed')
            ->where('receipt_id', $receipt->id)
            ->firstOrFail();
        $this->assertNotNull($ledger->gl_journal_entry_id);

        // (c) Voucher balance decremented by the applied amount.
        // Internal precision = currency_scale + 2 (5 for EUR).
        $voucher->refresh();
        $this->assertSame('35.00000', $voucher->current_balance);

        // (d) Status transitioned to PartiallyRedeemed (positive residual).
        $this->assertSame(VoucherStatus::PartiallyRedeemed, $voucher->status);

        // (e) GL journal entry posted: Dr VoucherLiability / Cr PosTenderClearing.
        $entry = JournalEntry::query()
            ->where('id', $ledger->gl_journal_entry_id)
            ->with('lines')
            ->firstOrFail();
        $this->assertCount(2, $entry->lines);
    }

    public function test_full_redemption_transitions_voucher_to_fully_redeemed(): void
    {
        $voucher = $this->seedVoucher('SV-FULL-001', '15.00');
        $receipt = $this->seedPendingSealReceipt('15.000');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [[
                'amount' => '15.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                'instrument_type' => 'store_voucher',
                'instrument_serial' => 'SV-FULL-001',
            ]],
        );

        $voucher->refresh();
        $this->assertSame('0.00000', $voucher->current_balance);
        $this->assertSame(VoucherStatus::FullyRedeemed, $voucher->status);
    }

    public function test_redemption_with_insufficient_balance_throws_and_rolls_back_receipt_payment(): void
    {
        $voucher = $this->seedVoucher('SV-LOW-001', '5.00');
        $receipt = $this->seedPendingSealReceipt('15.000');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        $this->expectException(VoucherInsufficientBalanceException::class);

        try {
            $service->processReceiptPayments(
                receiptId: $receipt->id,
                payments: [[
                    'amount' => '15.000',
                    'payment_method_id' => $this->voucherMethod->id,
                    'repository_id' => $this->voucherRepo->id,
                    'instrument_type' => 'store_voucher',
                    'instrument_serial' => 'SV-LOW-001',
                ]],
            );
        } finally {
            // The wrapping DB::transaction must have rolled back: no
            // pos_receipt_payments row, no voucher_ledger row, voucher
            // balance unchanged.
            $this->assertDatabaseMissing('pos_receipt_payments', [
                'receipt_id' => $receipt->id,
            ]);
            $this->assertDatabaseMissing('voucher_ledger', [
                'voucher_id' => $voucher->id,
                'event' => 'redeemed',
            ]);
            $voucher->refresh();
            $this->assertSame('5.00000', $voucher->current_balance);
        }
    }

    public function test_redemption_against_unknown_voucher_throws_invalid_status_and_rolls_back(): void
    {
        // No voucher seeded for this code.
        $receipt = $this->seedPendingSealReceipt('15.000');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        $this->expectException(VoucherInvalidStatusException::class);
        $this->expectExceptionMessage('UNKNOWN-VOUCHER');

        try {
            $service->processReceiptPayments(
                receiptId: $receipt->id,
                payments: [[
                    'amount' => '15.000',
                    'payment_method_id' => $this->voucherMethod->id,
                    'repository_id' => $this->voucherRepo->id,
                    'instrument_type' => 'store_voucher',
                    'instrument_serial' => 'UNKNOWN-VOUCHER',
                ]],
            );
        } finally {
            $this->assertDatabaseMissing('pos_receipt_payments', [
                'receipt_id' => $receipt->id,
            ]);
        }
    }

    public function test_redemption_against_expired_voucher_throws_and_rolls_back(): void
    {
        Voucher::factory()
            ->forTerminal($this->terminal)
            ->expired()
            ->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => 'SV-EXPIRED-001',
                'currency' => 'EUR',
                'initial_balance' => '50.00',
                'current_balance' => '50.00',
                'issued_by_user_id' => $this->cashier->id,
            ]);

        $receipt = $this->seedPendingSealReceipt('15.000');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        // Expired voucher → status is Expired, so the status guard fires
        // BEFORE the expiry guard (status check is first per redeem()'s
        // validation matrix). Either way, it throws and rolls back.
        $this->expectException(\RuntimeException::class);

        try {
            $service->processReceiptPayments(
                receiptId: $receipt->id,
                payments: [[
                    'amount' => '15.000',
                    'payment_method_id' => $this->voucherMethod->id,
                    'repository_id' => $this->voucherRepo->id,
                    'instrument_type' => 'store_voucher',
                    'instrument_serial' => 'SV-EXPIRED-001',
                ]],
            );
        } finally {
            $this->assertDatabaseMissing('pos_receipt_payments', [
                'receipt_id' => $receipt->id,
            ]);
        }
    }

    public function test_cash_payment_does_no_t_invoke_voucher_redemption(): void
    {
        // Regression guard: a non-voucher payment must not touch the voucher
        // service. Seed nothing but the cash payment method.
        $cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'cash',
        ]);

        $cashAccount = Account::where([
            'company_id' => $this->company->id,
            'system_purpose' => SystemAccountPurpose::Cash->value,
        ])->firstOrFail();

        $cashRepo = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash Drawer',
            'code' => 'CASH-01',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'EUR',
        ]);

        $receipt = $this->seedPendingSealReceipt('15.000');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [[
                'amount' => '15.000',
                'payment_method_id' => $cashMethod->id,
                'repository_id' => $cashRepo->id,
            ]],
        );

        // No voucher_ledger rows should exist for this receipt.
        $this->assertDatabaseMissing('voucher_ledger', [
            'receipt_id' => $receipt->id,
        ]);

        // Receipt payment was created normally.
        $this->assertDatabaseHas('pos_receipt_payments', [
            'receipt_id' => $receipt->id,
            'payment_method_code' => 'cash',
            'instrument_serial' => null,
        ]);
    }

    private static int $receiptCounter = 0;

    private function seedPendingSealReceipt(string $total): Receipt
    {
        return Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'total' => $total,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'currency' => 'EUR',
            'receipt_number' => sprintf('LOC-POS01-%d-%08d', date('Y'), ++self::$receiptCounter),
        ]);
    }

    private function seedVoucher(string $code, string $balance): Voucher
    {
        return Voucher::factory()
            ->forTerminal($this->terminal)
            ->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => $code,
                'currency' => 'EUR',
                'initial_balance' => $balance,
                'current_balance' => $balance,
                'issued_by_user_id' => $this->cashier->id,
            ]);
    }
}
