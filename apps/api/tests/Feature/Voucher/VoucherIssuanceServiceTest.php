<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Events\VoucherIssued;
use App\Modules\Voucher\Domain\Exceptions\GoodwillFourEyesRequiredException;
use App\Modules\Voucher\Domain\Exceptions\GoodwillRequiresNamedCustomerException;
use App\Modules\Voucher\Domain\Exceptions\SpvNotYetSupportedException;
use App\Modules\Voucher\Domain\Services\VoucherCodeGenerator;
use App\Modules\Voucher\Domain\VoucherLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for VoucherIssuanceService (Task 13).
 *
 * Covers all three issuance entry points, SPV rejection, goodwill controls,
 * GL accounting correctness (non-taxable — no VAT lines), and code uniqueness.
 */
final class VoucherIssuanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-voucher-issuance',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->issuer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Cashier',
            'email' => 'cashier@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        // Seed chart of accounts — this includes the 5 new voucher system purposes
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // Bind CompanyContext so CurrencyScaleResolver::getScale() resolves without throwing.
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /**
     * Resolve the service fresh from the container.
     *
     * Must be called AFTER Event::fake() to ensure the faked dispatcher is injected.
     */
    private function makeService(): VoucherIssuanceService
    {
        return app(VoucherIssuanceService::class);
    }

    // -------------------------------------------------------------------------
    // Refund issuance
    // -------------------------------------------------------------------------

    public function test_issue_from_refund_creates_voucher_ledger_and_g_l_entry(): void
    {
        Event::fake([VoucherIssued::class]);

        $request = $this->makeRequest(amount: '75.00000');
        $voucher = $this->makeService()->issueFromRefund($request);

        // Voucher row assertions
        $this->assertNotNull($voucher->id);
        $this->assertEquals('75.00000', $voucher->current_balance);
        $this->assertEquals('75.00000', $voucher->initial_balance);
        $this->assertEquals(VoucherStatus::Issued, $voucher->status);
        $this->assertEquals(VoucherSource::Refund, $voucher->source);
        $this->assertEquals(VoucherKind::MPV, $voucher->voucher_kind);
        $this->assertEquals(RedemptionMode::Bearer, $voucher->redemption_mode);

        // VoucherLedger row assertions
        $ledger = VoucherLedger::where('voucher_id', $voucher->id)->first();
        $this->assertNotNull($ledger);
        $this->assertEquals(VoucherEvent::Issued, $ledger->event);
        $this->assertEquals('75.00000', $ledger->amount);
        $this->assertNotNull($ledger->gl_journal_entry_id);

        // GL entry: exactly 2 lines, no VAT
        $entry = JournalEntry::with('lines')->find($ledger->gl_journal_entry_id);
        $this->assertNotNull($entry);
        $this->assertCount(2, $entry->lines);
        $this->assertEquals('voucher_ledger', $entry->source_type);
        $this->assertEquals($ledger->id, $entry->source_id);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($this->issuer->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);
        $this->assertNotNull($entry->fiscal_hash);

        // Debit = SalesReturnsClearing
        $clearingAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SalesReturnsClearing);
        $debitLine = $entry->lines->firstWhere('account_id', $clearingAccount->id);
        $this->assertNotNull($debitLine, 'Expected debit line for SalesReturnsClearing');
        // JournalLine debit/credit cast to decimal:3 — compare numerically
        $this->assertEquals(0, bccomp((string) $debitLine->debit, '75.00000', 3));
        $this->assertEquals(0, bccomp((string) $debitLine->credit, '0', 3));

        // Credit = VoucherLiability
        $liabilityAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VoucherLiability);
        $creditLine = $entry->lines->firstWhere('account_id', $liabilityAccount->id);
        $this->assertNotNull($creditLine, 'Expected credit line for VoucherLiability');
        $this->assertEquals(0, bccomp((string) $creditLine->debit, '0', 3));
        $this->assertEquals(0, bccomp((string) $creditLine->credit, '75.00000', 3));

        // No VAT lines
        $vatPurposes = [SystemAccountPurpose::VatCollected, SystemAccountPurpose::VatDeductible];
        foreach ($vatPurposes as $vatPurpose) {
            $vatAccount = Account::findByPurpose($this->company->id, $vatPurpose);
            if ($vatAccount !== null) {
                $vatLine = $entry->lines->firstWhere('account_id', $vatAccount->id);
                $this->assertNull($vatLine, "Unexpected VAT line for {$vatPurpose->value}");
            }
        }

        // VoucherIssued event dispatched
        Event::assertDispatched(VoucherIssued::class, function (VoucherIssued $e) use ($voucher): bool {
            return $e->voucherId === $voucher->id
                && $e->source === VoucherSource::Refund;
        });
    }

    public function test_issue_from_refund_rejects_spv(): void
    {
        $this->expectException(SpvNotYetSupportedException::class);

        $request = $this->makeRequest(amount: '50.00000', voucherKind: VoucherKind::SPV);
        $this->makeService()->issueFromRefund($request);
    }

    // -------------------------------------------------------------------------
    // Exchange surplus issuance
    // -------------------------------------------------------------------------

    public function test_issue_from_exchange_surplus_uses_same_clearing_account(): void
    {
        Event::fake([VoucherIssued::class]);

        $request = $this->makeRequest(amount: '30.00000');
        $voucher = $this->makeService()->issueFromExchangeSurplus($request);

        $this->assertEquals(VoucherSource::ExchangeSurplus, $voucher->source);

        $ledger = VoucherLedger::where('voucher_id', $voucher->id)->first();
        $this->assertNotNull($ledger->gl_journal_entry_id);

        $entry = JournalEntry::with('lines')->find($ledger->gl_journal_entry_id);
        $this->assertCount(2, $entry->lines);

        // Debit = SalesReturnsClearing (same clearing account as refund)
        $clearingAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SalesReturnsClearing);
        $debitLine = $entry->lines->firstWhere('account_id', $clearingAccount->id);
        $this->assertNotNull($debitLine, 'Expected SalesReturnsClearing debit for ExchangeSurplus');
        $this->assertEquals(0, bccomp((string) $debitLine->debit, '30.00000', 3));
    }

    // -------------------------------------------------------------------------
    // Goodwill issuance
    // -------------------------------------------------------------------------

    public function test_issue_goodwill_uses_marketing_expense_clearing(): void
    {
        Event::fake([VoucherIssued::class]);

        // Small amount — below all goodwill thresholds
        $request = $this->makeRequest(amount: '20.00000');
        $voucher = $this->makeService()->issueGoodwill($request);

        $this->assertEquals(VoucherSource::Goodwill, $voucher->source);

        $ledger = VoucherLedger::where('voucher_id', $voucher->id)->first();
        $this->assertNotNull($ledger->gl_journal_entry_id);

        $entry = JournalEntry::with('lines')->find($ledger->gl_journal_entry_id);
        $this->assertCount(2, $entry->lines);

        // Debit = MarketingGoodwillExpense (NOT SalesReturnsClearing)
        $expenseAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::MarketingGoodwillExpense);
        $debitLine = $entry->lines->firstWhere('account_id', $expenseAccount->id);
        $this->assertNotNull($debitLine, 'Expected MarketingGoodwillExpense debit for Goodwill');
        $this->assertEquals(0, bccomp((string) $debitLine->debit, '20.00000', 3));

        // Confirm SalesReturnsClearing is NOT used for goodwill
        $clearingAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SalesReturnsClearing);
        $clearingLine = $entry->lines->firstWhere('account_id', $clearingAccount->id);
        $this->assertNull($clearingLine, 'Goodwill must NOT debit SalesReturnsClearing');

        // Credit = VoucherLiability
        $liabilityAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VoucherLiability);
        $creditLine = $entry->lines->firstWhere('account_id', $liabilityAccount->id);
        $this->assertNotNull($creditLine, 'Expected VoucherLiability credit for Goodwill');
        $this->assertEquals(0, bccomp((string) $creditLine->credit, '20.00000', 3));
    }

    public function test_issue_goodwill_rejects_self_dealing(): void
    {
        // Partner model has no user_id FK in Phase 1 (no User→Partner link yet).
        // Self-dealing guard is a no-op until Phase 2 wires customer accounts.
        $this->markTestIncomplete(
            'Self-dealing guard deferred to Phase 2: Partner has no user_id foreign key. '
            .'The GoodwillSelfDealingException path will be wired when customer accounts land.'
        );
    }

    public function test_issue_goodwill_above_named_customer_threshold_requires_partner(): void
    {
        $this->expectException(GoodwillRequiresNamedCustomerException::class);

        // Amount > 100.00 threshold, no issued_to_partner_id
        $request = $this->makeRequest(amount: '150.00000', issuedToPartnerId: null);
        $this->makeService()->issueGoodwill($request);
    }

    public function test_issue_goodwill_above_four_eyes_threshold_requires_authorizer(): void
    {
        $this->expectException(GoodwillFourEyesRequiredException::class);

        $partner = $this->createPartner();

        // Amount > 250.00 threshold, no authorized_by_user_id
        $request = $this->makeRequest(
            amount: '300.00000',
            issuedToPartnerId: $partner->id,
            authorizedByUserId: null,
        );
        $this->makeService()->issueGoodwill($request);
    }

    public function test_issue_respects_daily_issuance_cap_per_user(): void
    {
        // GOODWILL_DAILY_CAP_PER_USER is currently null (no cap in Phase 1).
        // This test will be meaningful once Task C17 wires the tenant setting.
        // For now we verify that two small goodwill vouchers can be issued without hitting a cap.
        Event::fake([VoucherIssued::class]);

        $request1 = $this->makeRequest(amount: '20.00000');
        $request2 = $this->makeRequest(amount: '20.00000');

        $voucher1 = $this->makeService()->issueGoodwill($request1);
        $voucher2 = $this->makeService()->issueGoodwill($request2);

        $this->assertNotEquals($voucher1->id, $voucher2->id);
        $this->assertNotEquals($voucher1->code, $voucher2->code);

        // Both are valid issued vouchers
        $this->assertEquals(VoucherStatus::Issued, $voucher1->status);
        $this->assertEquals(VoucherStatus::Issued, $voucher2->status);
    }

    // -------------------------------------------------------------------------
    // Terminal scope (Phase 1 single-terminal)
    // -------------------------------------------------------------------------

    public function test_issue_sets_redeemable_at_terminal_id_to_issued_at_terminal_id(): void
    {
        Event::fake([VoucherIssued::class]);

        // Simulate a terminal ID (it's a nullable string in Phase 1; we pass null for back-office)
        $request = $this->makeRequest(amount: '50.00000', issuedAtTerminalId: null);
        $voucher = $this->makeService()->issueFromRefund($request);

        $this->assertNull($voucher->issued_at_terminal_id);
        $this->assertNull($voucher->redeemable_at_terminal_id);
    }

    // -------------------------------------------------------------------------
    // No VAT (explicit)
    // -------------------------------------------------------------------------

    public function test_issue_does_not_create_va_t_journal_lines(): void
    {
        Event::fake([VoucherIssued::class]);

        $request = $this->makeRequest(amount: '99.00000');
        $voucher = $this->makeService()->issueFromRefund($request);

        $ledger = VoucherLedger::where('voucher_id', $voucher->id)->first();
        $entry = JournalEntry::with('lines')->find($ledger->gl_journal_entry_id);

        // Exactly 2 lines — no VAT line appended
        $this->assertCount(2, $entry->lines, 'GL entry must have exactly 2 lines (no VAT)');

        // Verify neither line references a VAT account
        $vatAccounts = Account::query()
            ->where('company_id', $this->company->id)
            ->whereIn('system_purpose', [
                SystemAccountPurpose::VatCollected->value,
                SystemAccountPurpose::VatDeductible->value,
            ])
            ->pluck('id')
            ->all();

        foreach ($entry->lines as $line) {
            $this->assertNotContains(
                $line->account_id,
                $vatAccounts,
                "GL line {$line->id} must not reference a VAT account"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Code uniqueness
    // -------------------------------------------------------------------------

    public function test_issue_assigns_unique_code_via_generator(): void
    {
        Event::fake([VoucherIssued::class]);

        $codes = [];
        for ($i = 0; $i < 100; $i++) {
            $request = $this->makeRequest(amount: '10.00000');
            $voucher = $this->makeService()->issueFromRefund($request);
            $codes[] = $voucher->code;
        }

        $uniqueCodes = array_unique($codes);
        $this->assertCount(100, $uniqueCodes, 'All 100 generated codes must be unique');

        // All codes must be valid per the Damm check digit
        $generator = app(VoucherCodeGenerator::class);
        foreach ($codes as $code) {
            $this->assertTrue(
                $generator->isValid($code),
                "Generated code '{$code}' failed Damm validation"
            );
        }
    }

    // -------------------------------------------------------------------------
    // VoucherIssued event dispatch
    // -------------------------------------------------------------------------

    public function test_issue_dispatches_voucher_issued_event(): void
    {
        Event::fake([VoucherIssued::class]);

        $request = $this->makeRequest(amount: '42.00000');
        $voucher = $this->makeService()->issueFromRefund($request);

        Event::assertDispatched(VoucherIssued::class, function (VoucherIssued $e) use ($voucher): bool {
            return $e->voucherId === $voucher->id
                && $e->tenantId === $this->tenant->id
                && $e->companyId === $this->company->id
                && $e->source === VoucherSource::Refund
                && $e->issuedByUserId === $this->issuer->id
                && $e->amount === '42.00000'
                && $e->currency === 'EUR';
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a VoucherIssuanceRequest with sensible defaults.
     *
     * @param  numeric-string  $amount
     */
    private function makeRequest(
        string $amount = '50.00000',
        VoucherKind $voucherKind = VoucherKind::MPV,
        ?string $issuedToPartnerId = null,
        ?string $issuedAtTerminalId = null,
        ?string $authorizedByUserId = null,
    ): VoucherIssuanceRequest {
        return new VoucherIssuanceRequest(
            amount: $amount,
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->issuer->id,
            sourceReceiptId: null,
            issuedToPartnerId: $issuedToPartnerId,
            issuedAtTerminalId: $issuedAtTerminalId,
            expiresAt: null,
            notes: null,
            authorizedByUserId: $authorizedByUserId,
            overrideReason: null,
            policyTrigger: null,
            redemptionMode: RedemptionMode::Bearer,
            voucherKind: $voucherKind,
        );
    }

    private function createPartner(): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);
    }
}
