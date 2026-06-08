<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Application\Services\VoucherRedemptionService;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherFullyRedeemed;
use App\Modules\Voucher\Domain\Events\VoucherPartiallyRedeemed;
use App\Modules\Voucher\Domain\Exceptions\VoucherDuplicateInTransactionException;
use App\Modules\Voucher\Domain\Exceptions\VoucherExpiredException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInsufficientBalanceException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInvalidStatusException;
use App\Modules\Voucher\Domain\Exceptions\VoucherNotForThisCustomerException;
use App\Modules\Voucher\Domain\Exceptions\VoucherNotForThisTerminalException;
use App\Modules\Voucher\Domain\Voucher;
use App\Shared\Domain\CurrencyScale;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for VoucherRedemptionService (Task 14).
 *
 * Covers:
 *  - Full redeem → status FullyRedeemed, balance 0, GL correct legs, event dispatched
 *  - Partial redeem → status PartiallyRedeemed, remaining balance
 *  - Second partial against PartiallyRedeemed voucher
 *  - All 6 typed exceptions (terminal mismatch, expired, invalid status, insufficient balance,
 *    customer mismatch, duplicate-in-transaction)
 *  - Currency mismatch exception
 *  - CustomerBound happy path
 *  - RoundingAdjustment for EUR (scale 2) and TND (scale 3) residuals
 *  - GL correctness: exactly 2 lines, Debit VoucherLiability / Credit PosTenderClearing, no VAT
 *  - RoundingAdjustment GL: Debit VoucherLiability / Credit RoundingLossExpense, no VAT
 *  - VoucherPartiallyRedeemed and VoucherFullyRedeemed events dispatched
 */
final class VoucherRedemptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Terminal $terminalA;

    private Terminal $terminalB;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-voucher-redemption',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX789',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Cashier',
            'email' => 'cashier-r@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        // Seed chart of accounts — includes all voucher system purposes + PosTenderClearing
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminalA = $this->createTerminal('POS-A');
        $this->terminalB = $this->createTerminal('POS-B');

        // Bind CompanyContext so CurrencyScaleResolver::getScale() resolves without throwing.
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // -------------------------------------------------------------------------
    // Full redeem
    // -------------------------------------------------------------------------

    public function test_full_redeem_decrements_balance_to_zero_and_marks_fully_redeemed(): void
    {
        Event::fake([VoucherFullyRedeemed::class]);

        $voucher = $this->issueVoucher('50.00000', 'EUR', $this->terminalA->id);
        $receiptId = (string) Str::uuid();

        $result = $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '50.00',
                currency: 'EUR',
                receiptId: $receiptId,
                terminalId: $this->terminalA->id,
            )
        );

        $this->assertTrue($result->fullyRedeemed);
        $this->assertEquals(VoucherStatus::FullyRedeemed, $result->voucher->status);
        $this->assertEquals(0, bccomp($result->newBalance, '0', 4));
        $this->assertNull($result->roundingEntry);

        // Ledger row
        $this->assertEquals(VoucherEvent::Redeemed, $result->redemptionEntry->event);
        $this->assertNotNull($result->redemptionEntry->gl_journal_entry_id);

        // DB projection
        $this->assertEquals(VoucherStatus::FullyRedeemed, $voucher->fresh()->status);
        $this->assertEquals(0, bccomp($voucher->fresh()->current_balance, '0', 4));
    }

    public function test_partial_redeem_marks_partially_redeemed_with_remaining_balance(): void
    {
        Event::fake([VoucherPartiallyRedeemed::class]);

        $voucher = $this->issueVoucher('50.00000', 'EUR', $this->terminalA->id);
        $receiptId = (string) Str::uuid();

        $result = $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '20.00',
                currency: 'EUR',
                receiptId: $receiptId,
                terminalId: $this->terminalA->id,
            )
        );

        $this->assertFalse($result->fullyRedeemed);
        $this->assertEquals(VoucherStatus::PartiallyRedeemed, $result->voucher->status);
        // Expected internal balance: 50.00000 - 20.00 = 30.0000 (at scale 4)
        $this->assertEquals(0, bccomp($result->newBalance, '30.0000', 4));
        $this->assertNull($result->roundingEntry);

        // DB projection
        $this->assertEquals(VoucherStatus::PartiallyRedeemed, $voucher->fresh()->status);
        $this->assertEquals(0, bccomp($voucher->fresh()->current_balance, '30.0000', 4));
    }

    public function test_second_partial_redeem_against_partially_redeemed_voucher(): void
    {
        Event::fake([VoucherPartiallyRedeemed::class, VoucherFullyRedeemed::class]);

        $voucher = $this->issueVoucher('50.00000', 'EUR', $this->terminalA->id);

        // First redemption: 20.00
        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '20.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );

        // Second redemption: 25.00 → balance should be 5.0000
        $result = $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '25.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );

        $this->assertFalse($result->fullyRedeemed);
        $this->assertEquals(VoucherStatus::PartiallyRedeemed, $result->voucher->status);
        $this->assertEquals(0, bccomp($result->newBalance, '5.0000', 4));
    }

    // -------------------------------------------------------------------------
    // Single-terminal guard (Phase 1 critical)
    // -------------------------------------------------------------------------

    public function test_redeem_rejects_other_terminal(): void
    {
        Event::fake();

        $voucher = $this->issueVoucher('50.00000', 'EUR', $this->terminalA->id);

        $this->expectException(VoucherNotForThisTerminalException::class);

        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '50.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                // Terminal B — different from where the voucher was issued
                terminalId: $this->terminalB->id,
            )
        );
    }

    // -------------------------------------------------------------------------
    // Expiry
    // -------------------------------------------------------------------------

    public function test_redeem_rejects_expired_voucher(): void
    {
        Event::fake();

        $voucher = $this->issueVoucher('30.00000', 'EUR', $this->terminalA->id);

        // Force expires_at to the past directly via DB (bypass service)
        $voucher->update(['expires_at' => Carbon::now()->subDay()]);

        $this->expectException(VoucherExpiredException::class);

        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '30.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );
    }

    // -------------------------------------------------------------------------
    // Invalid status
    // -------------------------------------------------------------------------

    public function test_redeem_rejects_voided_voucher(): void
    {
        Event::fake();

        $voucher = $this->issueVoucher('30.00000', 'EUR', $this->terminalA->id);
        $voucher->update(['status' => VoucherStatus::Voided]);

        $this->expectException(VoucherInvalidStatusException::class);

        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '30.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );
    }

    public function test_redeem_rejects_fully_redeemed_voucher(): void
    {
        Event::fake([VoucherFullyRedeemed::class]);

        $voucher = $this->issueVoucher('10.00000', 'EUR', $this->terminalA->id);

        // First redeem: fully consume it
        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '10.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );

        $this->expectException(VoucherInvalidStatusException::class);

        // Second redeem attempt against a FullyRedeemed voucher
        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '1.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );
    }

    // -------------------------------------------------------------------------
    // Insufficient balance
    // -------------------------------------------------------------------------

    public function test_redeem_rejects_insufficient_balance(): void
    {
        Event::fake();

        $voucher = $this->issueVoucher('10.00000', 'EUR', $this->terminalA->id);

        $this->expectException(VoucherInsufficientBalanceException::class);

        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '50.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );
    }

    // -------------------------------------------------------------------------
    // Currency mismatch
    // -------------------------------------------------------------------------

    public function test_redeem_rejects_currency_mismatch(): void
    {
        Event::fake();

        // Voucher issued in EUR
        $voucher = $this->issueVoucher('50.00000', 'EUR', $this->terminalA->id);

        $this->expectException(VoucherInvalidStatusException::class);

        // Attempt to redeem in TND
        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '50.000',
                currency: 'TND',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );
    }

    // -------------------------------------------------------------------------
    // CustomerBound mode
    // -------------------------------------------------------------------------

    public function test_redeem_rejects_customer_bound_voucher_for_wrong_partner(): void
    {
        Event::fake();

        $partnerX = $this->createPartner();
        $partnerY = $this->createPartner();

        $voucher = $this->issueCustomerBoundVoucher('40.00000', 'EUR', $this->terminalA->id, $partnerX->id);

        $this->expectException(VoucherNotForThisCustomerException::class);

        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '40.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
                partnerId: $partnerY->id,
            )
        );
    }

    public function test_redeem_accepts_customer_bound_voucher_for_correct_partner(): void
    {
        Event::fake([VoucherFullyRedeemed::class]);

        $partnerX = $this->createPartner();
        $voucher = $this->issueCustomerBoundVoucher('40.00000', 'EUR', $this->terminalA->id, $partnerX->id);

        $result = $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '40.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
                partnerId: $partnerX->id,
            )
        );

        $this->assertTrue($result->fullyRedeemed);
    }

    // -------------------------------------------------------------------------
    // Duplicate-in-transaction guard
    // -------------------------------------------------------------------------

    public function test_redeem_rejects_duplicate_voucher_in_same_transaction(): void
    {
        Event::fake([VoucherPartiallyRedeemed::class]);

        $voucher = $this->issueVoucher('100.00000', 'EUR', $this->terminalA->id);
        $receiptId = (string) Str::uuid();

        // First redemption on this receipt — should succeed
        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '20.00',
                currency: 'EUR',
                receiptId: $receiptId,
                terminalId: $this->terminalA->id,
            )
        );

        $this->expectException(VoucherDuplicateInTransactionException::class);

        // Second redemption on the SAME receipt — must fail
        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '20.00',
                currency: 'EUR',
                receiptId: $receiptId,
                terminalId: $this->terminalA->id,
            )
        );
    }

    // -------------------------------------------------------------------------
    // Rounding adjustment (sub-min-unit residual)
    // -------------------------------------------------------------------------

    public function test_residual_below_min_currency_unit_triggers_rounding_adjustment_eur(): void
    {
        Event::fake([VoucherFullyRedeemed::class]);

        // EUR scale = 2, internal scale = 4, min unit = 0.01
        // Manually create a voucher with a sub-cent residual in internal balance.
        // internal balance of 0.0050 (0.005 stored at scale 4 = below 0.01 EUR)
        $voucher = $this->createVoucherWithInternalBalance('0.0050', 'EUR', $this->terminalA->id);

        // Apply 0.00 EUR (we just want to trigger the rounding cleanup)
        // Actually: we need to apply an amount that LEAVES a residual < 0.01.
        // Let's use a balance of 10.0050 (10.00 EUR + sub-cent residual) and redeem 10.00
        $voucher2 = $this->createVoucherWithInternalBalance('10.0050', 'EUR', $this->terminalA->id);

        $result = $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher2->code,
                appliedAmount: '10.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );

        // Sub-cent residual (0.0050) should trigger RoundingAdjustment
        $this->assertTrue($result->fullyRedeemed);
        $this->assertNotNull($result->roundingEntry);
        $this->assertEquals(VoucherEvent::RoundingAdjustment, $result->roundingEntry->event);
        $this->assertNotNull($result->roundingEntry->gl_journal_entry_id);
        $this->assertEquals(VoucherStatus::FullyRedeemed, $result->voucher->status);
        $this->assertEquals(0, bccomp($result->newBalance, '0', 4));
    }

    public function test_residual_below_min_currency_unit_triggers_rounding_adjustment_tnd(): void
    {
        Event::fake([VoucherFullyRedeemed::class]);

        // TND scale = 3, internal scale = 5, min unit = 0.001
        // Internal balance 10.00050 (10.000 TND + sub-millime residual), redeem 10.000
        $voucher = $this->createVoucherWithInternalBalance('10.00050', 'TND', $this->terminalA->id);

        $result = $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '10.000',
                currency: 'TND',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );

        // Sub-millime residual (0.00050) should trigger RoundingAdjustment
        $this->assertTrue($result->fullyRedeemed);
        $this->assertNotNull($result->roundingEntry);
        $this->assertEquals(VoucherEvent::RoundingAdjustment, $result->roundingEntry->event);
        $this->assertEquals(VoucherStatus::FullyRedeemed, $result->voucher->status);
        $this->assertEquals(0, bccomp($result->newBalance, '0', 5));
    }

    // -------------------------------------------------------------------------
    // Event dispatch
    // -------------------------------------------------------------------------

    public function test_dispatches_voucher_partially_redeemed_event(): void
    {
        Event::fake([VoucherPartiallyRedeemed::class]);

        $voucher = $this->issueVoucher('50.00000', 'EUR', $this->terminalA->id);
        $receiptId = (string) Str::uuid();

        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '20.00',
                currency: 'EUR',
                receiptId: $receiptId,
                terminalId: $this->terminalA->id,
            )
        );

        Event::assertDispatched(VoucherPartiallyRedeemed::class, function (VoucherPartiallyRedeemed $e) use ($voucher, $receiptId): bool {
            return $e->voucherId === $voucher->id
                && $e->receiptId === $receiptId
                && $e->appliedAmount === '20.00'
                && $e->currency === 'EUR';
        });
    }

    public function test_dispatches_voucher_fully_redeemed_event(): void
    {
        Event::fake([VoucherFullyRedeemed::class]);

        $voucher = $this->issueVoucher('50.00000', 'EUR', $this->terminalA->id);
        $receiptId = (string) Str::uuid();

        $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '50.00',
                currency: 'EUR',
                receiptId: $receiptId,
                terminalId: $this->terminalA->id,
            )
        );

        Event::assertDispatched(VoucherFullyRedeemed::class, function (VoucherFullyRedeemed $e) use ($voucher, $receiptId): bool {
            return $e->voucherId === $voucher->id
                && $e->receiptId === $receiptId
                && $e->appliedAmount === '50.00'
                && $e->currency === 'EUR'
                && ! $e->hadRoundingAdjustment;
        });
    }

    // -------------------------------------------------------------------------
    // GL correctness — Redeemed event
    // -------------------------------------------------------------------------

    public function test_g_l_entry_has_correct_legs_no_va_t_lines(): void
    {
        Event::fake([VoucherFullyRedeemed::class]);

        $voucher = $this->issueVoucher('75.00000', 'EUR', $this->terminalA->id);
        $receiptId = (string) Str::uuid();

        $result = $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '75.00',
                currency: 'EUR',
                receiptId: $receiptId,
                terminalId: $this->terminalA->id,
            )
        );

        $glEntry = JournalEntry::with('lines')->find($result->redemptionEntry->gl_journal_entry_id);
        $this->assertNotNull($glEntry);

        // Exactly 2 lines — no VAT lines
        $this->assertCount(2, $glEntry->lines, 'Redemption GL entry must have exactly 2 lines (no VAT)');
        $this->assertEquals('voucher_ledger', $glEntry->source_type);
        $this->assertEquals($result->redemptionEntry->id, $glEntry->source_id);

        // Debit = VoucherLiability
        $liabilityAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VoucherLiability);
        $debitLine = $glEntry->lines->firstWhere('account_id', $liabilityAccount->id);
        $this->assertNotNull($debitLine, 'Expected debit line for VoucherLiability');
        $this->assertEquals(0, bccomp((string) $debitLine->debit, '75.00', 2));
        $this->assertEquals(0, bccomp((string) $debitLine->credit, '0', 2));

        // Credit = PosTenderClearing
        $clearingAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PosTenderClearing);
        $creditLine = $glEntry->lines->firstWhere('account_id', $clearingAccount->id);
        $this->assertNotNull($creditLine, 'Expected credit line for PosTenderClearing');
        $this->assertEquals(0, bccomp((string) $creditLine->debit, '0', 2));
        $this->assertEquals(0, bccomp((string) $creditLine->credit, '75.00', 2));

        // No VAT account lines
        $vatAccounts = Account::query()
            ->where('company_id', $this->company->id)
            ->whereIn('system_purpose', [
                SystemAccountPurpose::VatCollected->value,
                SystemAccountPurpose::VatDeductible->value,
            ])
            ->pluck('id')
            ->all();

        foreach ($glEntry->lines as $line) {
            $this->assertNotContains(
                $line->account_id,
                $vatAccounts,
                "GL line {$line->id} must not reference a VAT account"
            );
        }
    }

    public function test_rounding_adjustment_g_l_has_correct_legs(): void
    {
        Event::fake([VoucherFullyRedeemed::class]);

        // EUR: internal scale 4, redeem 10.00 leaving 0.0050 sub-cent residual
        $voucher = $this->createVoucherWithInternalBalance('10.0050', 'EUR', $this->terminalA->id);

        $result = $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: $voucher->code,
                appliedAmount: '10.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );

        $this->assertNotNull($result->roundingEntry);
        $roundingGl = JournalEntry::with('lines')->find($result->roundingEntry->gl_journal_entry_id);
        $this->assertNotNull($roundingGl);
        $this->assertCount(2, $roundingGl->lines, 'RoundingAdjustment GL must have exactly 2 lines');

        // Debit = VoucherLiability
        $liabilityAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VoucherLiability);
        $debitLine = $roundingGl->lines->firstWhere('account_id', $liabilityAccount->id);
        $this->assertNotNull($debitLine, 'Expected debit line for VoucherLiability in rounding GL');

        // Credit = RoundingLossExpense
        $roundingExpense = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::RoundingLossExpense);
        $creditLine = $roundingGl->lines->firstWhere('account_id', $roundingExpense->id);
        $this->assertNotNull($creditLine, 'Expected credit line for RoundingLossExpense in rounding GL');
    }

    // -------------------------------------------------------------------------
    // Case-insensitive code lookup
    // -------------------------------------------------------------------------

    public function test_code_lookup_is_case_insensitive(): void
    {
        Event::fake([VoucherFullyRedeemed::class]);

        $voucher = $this->issueVoucher('20.00000', 'EUR', $this->terminalA->id);

        $result = $this->makeService()->redeem(
            $this->makeRequest(
                voucherCode: strtolower($voucher->code),
                appliedAmount: '20.00',
                currency: 'EUR',
                receiptId: (string) Str::uuid(),
                terminalId: $this->terminalA->id,
            )
        );

        $this->assertTrue($result->fullyRedeemed);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeService(): VoucherRedemptionService
    {
        return app(VoucherRedemptionService::class);
    }

    private function makeRequest(
        string $voucherCode,
        string $appliedAmount,
        string $currency,
        string $receiptId,
        string $terminalId,
        ?string $partnerId = null,
        ?string $authorizedByUserId = null,
    ): VoucherRedemptionRequest {
        return new VoucherRedemptionRequest(
            voucherCode: $voucherCode,
            appliedAmount: $appliedAmount,
            currency: $currency,
            receiptId: $receiptId,
            cashierId: $this->cashier->id,
            terminalId: $terminalId,
            partnerId: $partnerId,
            authorizedByUserId: $authorizedByUserId,
            policyTrigger: null,
        );
    }

    /**
     * Issue a Bearer MPV voucher via the real VoucherIssuanceService.
     *
     * @param  numeric-string  $amount
     */
    private function issueVoucher(string $amount, string $currency, string $terminalId): Voucher
    {
        Event::fake(); // suppress issuance events

        $issuanceService = app(VoucherIssuanceService::class);

        $issuanceRequest = new VoucherIssuanceRequest(
            amount: $amount,
            currency: $currency,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->cashier->id,
            sourceReceiptId: null,
            issuedToPartnerId: null,
            issuedAtTerminalId: $terminalId,
            expiresAt: null,
            notes: null,
            authorizedByUserId: null,
            overrideReason: null,
            policyTrigger: null,
            redemptionMode: RedemptionMode::Bearer,
        );

        return $issuanceService->issueFromRefund($issuanceRequest);
    }

    /**
     * Issue a CustomerBound MPV voucher via the real VoucherIssuanceService.
     *
     * @param  numeric-string  $amount
     */
    private function issueCustomerBoundVoucher(
        string $amount,
        string $currency,
        string $terminalId,
        string $partnerId,
    ): Voucher {
        Event::fake();

        $issuanceService = app(VoucherIssuanceService::class);

        $issuanceRequest = new VoucherIssuanceRequest(
            amount: $amount,
            currency: $currency,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->cashier->id,
            sourceReceiptId: null,
            issuedToPartnerId: $partnerId,
            issuedAtTerminalId: $terminalId,
            expiresAt: null,
            notes: null,
            authorizedByUserId: null,
            overrideReason: null,
            policyTrigger: null,
            redemptionMode: RedemptionMode::CustomerBound,
        );

        return $issuanceService->issueFromRefund($issuanceRequest);
    }

    /**
     * Create a voucher with a manually-set internal balance (bypassing VoucherIssuanceService)
     * for use in rounding-adjustment tests where the balance has sub-minor-unit precision.
     *
     * @param  numeric-string  $internalBalance  Balance at currency_scale+2 precision
     */
    private function createVoucherWithInternalBalance(
        string $internalBalance,
        string $currency,
        string $terminalId,
    ): Voucher {
        // Use the issuance service to create a properly-minted voucher with a valid code
        // and GL entry, then directly overwrite current_balance for the rounding test.
        $scale = CurrencyScale::for($currency);
        $internalScale = $scale + 2;

        // Issue with a round amount (the actual internal balance we set below may differ)
        $roundAmount = CurrencyScale::bcformat($internalBalance, $scale);

        /** @var numeric-string $roundAmountInternal */
        $roundAmountInternal = CurrencyScale::bcformat($roundAmount, $internalScale);

        $voucher = $this->issueVoucher($roundAmountInternal, $currency, $terminalId);

        // Override current_balance with the exact internal precision value for the test
        $voucher->update(['current_balance' => $internalBalance]);
        $voucher->refresh();

        return $voucher;
    }

    private function createTerminal(string $code): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => $code,
            'name' => "Terminal {$code}",
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => (int) date('Y'),
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }

    private function createPartner(): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer '.Str::random(4),
            'type' => PartnerType::Customer,
        ]);
    }
}
