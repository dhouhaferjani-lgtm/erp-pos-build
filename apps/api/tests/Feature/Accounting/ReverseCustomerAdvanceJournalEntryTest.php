<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA `DPA-REV2-A` / task A4 — `reverseCustomerAdvanceJournalEntry()`.
 *
 * The missing half of the customer-advance idiom (plan §1.1, A-D1). The supplier
 * mirror `reverseSupplierAdvanceJournalEntry()` (`GeneralLedgerService.php:496-576`)
 * has existed all along; this is its customer twin, with the legs on the sides a
 * customer LIABILITY requires:
 *
 *   Dr SystemAccountPurpose::CustomerAdvance  partner-tagged  line_order 0
 *   Cr the repository's gl_account            untagged        line_order 1
 *   source_type = 'customer_advance_refund'
 *   source_id   = the REVERSAL payment id (D-9 — never the original)
 *
 * It is the exact algebraic inverse of `createCustomerAdvanceJournalEntry()`
 * (`:448-464`), which posts Dr cash / Cr CustomerAdvance(partner).
 */
final class ReverseCustomerAdvanceJournalEntryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private GeneralLedgerService $gl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'name' => 'Advance Reversal GL Tenant',
            'slug' => 'advrevgl-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Advance Reversal GL Co',
            'legal_name' => 'Advance Reversal GL Co SARL',
            'tax_id' => 'TAX-ADVREVGL',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GL User',
            'email' => 'advrevgl-'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->partner = Partner::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Advance Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->gl = app(GeneralLedgerService::class);
    }

    public function test_it_posts_the_exact_inverse_of_the_customer_advance_entry(): void
    {
        $reversalPaymentId = Str::uuid()->toString();
        $bankAccountId = $this->accountId(SystemAccountPurpose::Bank);

        $entry = DB::transaction(fn () => $this->gl->reverseCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            reversalPaymentId: $reversalPaymentId,
            amount: '300.000',
            paymentMethodAccountId: $bankAccountId,
            date: now(),
            description: 'Reversal of customer advance',
            postedByUserId: $this->user->id,
            currencyCode: 'TND',
            mode: PostingMode::SynchronousInTransaction,
        ));

        self::assertSame('customer_advance_refund', $entry->source_type);
        self::assertSame(
            $reversalPaymentId,
            $entry->source_id,
            'D-9: source_id is the REVERSAL payment, never the original — otherwise two reversing '
            .'entries could collide on (source_type, source_id)',
        );
        self::assertSame(
            JournalCode::Misc,
            $entry->journal_code,
            "A-D1 / OQ-4: 'customer_advance_refund' has no JournalCode arm, so it falls to Misc/OD, "
            ."matching 'advance' and 'supplier_advance_refund'. Do NOT reclassify without the expert ruling.",
        );
        self::assertSame(JournalEntryStatus::Posted, $entry->fresh()?->status);

        /** @var JournalLine $debit */
        $debit = JournalLine::query()
            ->where('journal_entry_id', $entry->id)
            ->where('account_id', $this->accountId(SystemAccountPurpose::CustomerAdvance))
            ->firstOrFail();
        /** @var JournalLine $credit */
        $credit = JournalLine::query()
            ->where('journal_entry_id', $entry->id)
            ->where('account_id', $bankAccountId)
            ->firstOrFail();

        self::assertSame('300.000', $debit->debit, 'the liability is debited away');
        self::assertSame('0.000', $debit->credit);
        self::assertSame(
            $this->partner->id,
            $debit->partner_id,
            'the advance leg is partner-tagged — the liability is a per-partner subledger balance',
        );
        self::assertSame(0, $debit->line_order);

        self::assertSame('300.000', $credit->credit, 'cash leaves the till');
        self::assertSame('0.000', $credit->debit);
        self::assertNull($credit->partner_id, 'the cash leg is never partner-tagged');
        self::assertSame(1, $credit->line_order);

        self::assertSame(
            2,
            JournalLine::query()->where('journal_entry_id', $entry->id)->count(),
            'exactly two legs',
        );
    }

    /**
     * The `SynchronousInTransaction` guard (`DB::transactionLevel() < 1` →
     * `\LogicException`) is copied verbatim from the supplier sibling
     * (`GeneralLedgerService.php:513-515`).
     *
     * **It cannot be exercised behaviourally here.** Under `RefreshDatabase`
     * every test body already runs inside a transaction, so
     * `DB::transactionLevel()` is always >= 1 and the guard is INERT — the same
     * property this codebase records for the identical guard in
     * `InstrumentLifecycleService::cancelForPaymentReversal()` ("N2 fix: the
     * `DB::transactionLevel()` guard below only proves SOME transaction is open
     * — it is inert under `RefreshDatabase`").
     *
     * Asserting it structurally instead is the honest option: a behavioural test
     * would be green-by-vacuum, and deleting the coverage entirely would let the
     * guard be dropped silently. The `AfterCommit` default is the arm that IS
     * reachable, and it is exercised by the sibling tests above.
     */
    public function test_the_synchronous_posting_guard_matches_its_supplier_sibling(): void
    {
        $source = (string) file_get_contents(
            app_path('Modules/Accounting/Domain/Services/GeneralLedgerService.php')
        );

        self::assertStringContainsString(
            "throw new \\LogicException('reverseCustomerAdvanceJournalEntry: SynchronousInTransaction requires an enclosing database transaction",
            $source,
            'the customer reversal must refuse to mint a Draft that postEntryNow would orphan, '
            .'exactly as reverseSupplierAdvanceJournalEntry does',
        );
    }

    /**
     * Posting it against the entry it reverses must leave the partner's advance
     * subledger at exactly zero — the arithmetic proof that A-D1 is the inverse.
     */
    public function test_the_pair_nets_the_partner_advance_balance_to_zero(): void
    {
        $bankAccountId = $this->accountId(SystemAccountPurpose::Bank);
        $paymentId = Str::uuid()->toString();

        DB::transaction(function () use ($bankAccountId, $paymentId): void {
            $this->gl->createCustomerAdvanceJournalEntry(
                companyId: $this->company->id,
                partnerId: $this->partner->id,
                advanceId: $paymentId,
                amount: '300.000',
                paymentMethodAccountId: $bankAccountId,
                date: now(),
                user: $this->user,
                description: 'Advance received',
                currencyCode: 'TND',
                mode: PostingMode::SynchronousInTransaction,
            );

            $this->gl->reverseCustomerAdvanceJournalEntry(
                companyId: $this->company->id,
                partnerId: $this->partner->id,
                reversalPaymentId: Str::uuid()->toString(),
                amount: '300.000',
                paymentMethodAccountId: $bankAccountId,
                date: now(),
                description: 'Advance reversed',
                postedByUserId: $this->user->id,
                currencyCode: 'TND',
                mode: PostingMode::SynchronousInTransaction,
            );
        });

        self::assertSame(
            '0.000',
            app(PartnerBalanceService::class)
                ->getCustomerAdvanceBalance($this->company->id, $this->partner->id),
            'create + reverse must leave the advance subledger flat',
        );
    }

    private function accountId(SystemAccountPurpose $purpose): string
    {
        return Account::findByPurposeOrFail($this->company->id, $purpose)->id;
    }
}
