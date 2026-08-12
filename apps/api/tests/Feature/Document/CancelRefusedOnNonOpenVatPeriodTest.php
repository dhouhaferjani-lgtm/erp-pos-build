<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\PeriodLockRefusalCode;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Taxation\Domain\Exceptions\DocumentPeriodLockedException;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;

/**
 * R2-F1 — cancelling a document whose VAT period is no longer OPEN is REFUSED.
 *
 * The L2 lane made `DocumentPostingService::cancel()` reverse a posted document's
 * SEALED GL legs inside the cancel transaction, dated `now()` (GL gate ruling 6a:
 * back-dating the reversal to the original document's date would retroactively
 * rewrite a period that may already be CLOSED or FILED — a compliance-ready
 * ledger must never do that). That ruling carried a SECOND condition which was
 * ticketed but not implemented: when the original document's own period is
 * already CLOSED/FILED, the cancel itself must be refused — in FR/TN such an
 * invoice is not cancelled at all, it is credited (avoir).
 *
 * docs/superpowers/tickets/2026-08-06-l2-gl-vat-declaration-desync.md (§"Second
 * condition attached to ruling 6a", suggested fix 2)
 * docs/superpowers/tickets/2026-08-07-round2-rulings-record.md (R-c c2)
 */
#[UsesFrozenSeederFixture]
final class CancelRefusedOnNonOpenVatPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Partner $supplier;

    private Product $product;

    private AccountingService $accountingService;

    private DocumentPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Period Lock Tenant',
            'slug' => 'period-lock-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Period Lock Company',
            'legal_name' => 'Period Lock Company SARL',
            'tax_id' => 'TAX-PERIOD-LOCK',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Period Lock User',
            'email' => 'period-lock@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        (new TunisiaChartOfAccountsSeeder)->run($this->company->id, $this->tenant->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Clinique Al Amal',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Grossiste Pharma',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-PERIOD-'.uniqid(),
            'name' => 'Doliprane 1000mg',
            'type' => ProductType::Part,
            'cost_price' => '5.000',
            'sale_price' => '10.000',
            'is_active' => true,
        ]);

        $this->accountingService = app(AccountingService::class);
        $this->postingService = app(DocumentPostingService::class);
    }

    // ------------------------------------------------ sales invoice (AR) ---

    public function test_cancelling_a_sales_invoice_in_a_closed_period_is_refused(): void
    {
        $documentDate = Carbon::parse('2026-01-15');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Closed);

        $invoice = $this->postedInvoiceWithGl($documentDate);

        try {
            $this->postingService->cancel($invoice, 'January cleanup', $this->user->id);
            self::fail('Cancelling a document in a CLOSED VAT period must be refused');
        } catch (DocumentPeriodLockedException $exception) {
            self::assertSame(PeriodLockRefusalCode::PeriodClosed, $exception->refusalCode);
        }

        $invoice->refresh();
        self::assertSame(DocumentStatus::Posted, $invoice->status, 'The document must stay posted');
        self::assertSame(FiscalStatus::Sealed, $invoice->fiscal_status);
        self::assertNull($invoice->cancelled_at);
        self::assertNull($invoice->cancellation_reason);

        self::assertSame(
            0,
            JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
                ->where('source_id', $invoice->id)
                ->count(),
            'A refused cancel must write ZERO reversal journal entries',
        );
    }

    public function test_cancelling_a_sales_invoice_in_a_filed_period_is_refused(): void
    {
        $documentDate = Carbon::parse('2026-02-10');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Filed);

        $invoice = $this->postedInvoiceWithGl($documentDate);

        try {
            $this->postingService->cancel($invoice, 'February cleanup', $this->user->id);
            self::fail('Cancelling a document in a FILED VAT period must be refused');
        } catch (DocumentPeriodLockedException $exception) {
            self::assertSame(PeriodLockRefusalCode::PeriodFiled, $exception->refusalCode);
        }

        self::assertSame(DocumentStatus::Posted, $this->freshStatus($invoice));
        self::assertSame(
            0,
            JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
                ->where('source_id', $invoice->id)
                ->count(),
        );
    }

    /**
     * The refusal must roll back cleanly — no partial write survives, and the
     * document is still cancellable once the period is reopened. Anything less
     * would strand the document in a half-cancelled state.
     */
    public function test_the_refusal_rolls_back_cleanly_and_leaves_the_document_cancellable(): void
    {
        $documentDate = Carbon::parse('2026-03-20');
        $period = $this->vatPeriodFor($documentDate, VatPeriodStatus::Closed);

        $invoice = $this->postedInvoiceWithGl($documentDate);
        $entryCountBefore = JournalEntry::query()->count();
        // RefreshDatabase already holds one wrapping transaction; the assertion is
        // that the cancel's OWN transaction is gone, not that the level is zero.
        $transactionLevelBefore = DB::transactionLevel();

        try {
            $this->postingService->cancel($invoice, 'refused', $this->user->id);
            self::fail('Expected a refusal');
        } catch (DocumentPeriodLockedException) {
            // expected
        }

        self::assertSame(
            $transactionLevelBefore,
            DB::transactionLevel(),
            'The refusal must not leave the cancel transaction open',
        );
        self::assertSame(
            $entryCountBefore,
            JournalEntry::query()->count(),
            'Nothing at all may be persisted by a refused cancel',
        );
        self::assertSame(DocumentStatus::Posted, $this->freshStatus($invoice));

        // Reopen the period: the very same cancel must now succeed and write its
        // reversal, proving the refusal was the ONLY thing standing in the way.
        $period->update(['status' => VatPeriodStatus::Open]);

        $this->postingService->cancel($this->reload($invoice), 'now allowed', $this->user->id);

        self::assertSame(DocumentStatus::Cancelled, $this->freshStatus($invoice));
        self::assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
                ->where('source_id', $invoice->id)
                ->count(),
        );
    }

    // ------------------------------------------- period-range boundaries ---

    /**
     * Gate finding (GL I-2 / taxation I-1) — the FIRST day of a locked period.
     *
     * `period_start` / `period_end` are `date` columns, but SQLite stores dates as
     * TEXT and the model casts them to `Y-m-d H:i:s`. Binding a `Y-m-d` STRING
     * therefore compared `'2026-01-01 00:00:00' <= '2026-01-01'`, which is
     * lexicographically FALSE — a document dated exactly on `period_start`
     * escaped the lock in the test environment (PostgreSQL, which compares real
     * dates, was always correct). Binding the Carbon instance lets the driver's
     * own date grammar decide, which is what
     * `FiscalPeriodResolverService::isDateInClosedPeriod()` has always done.
     */
    public function test_a_document_dated_exactly_on_period_start_is_refused(): void
    {
        $periodStart = Carbon::parse('2026-09-01');
        $period = $this->vatPeriodFor($periodStart, VatPeriodStatus::Closed);
        self::assertSame(
            '2026-09-01',
            $period->period_start->toDateString(),
            'Precondition: the document date really is the first day of the period',
        );

        $invoice = $this->postedInvoiceWithGl($periodStart);

        try {
            $this->postingService->cancel($invoice, 'first day of a closed period', $this->user->id);
            self::fail('A document dated on period_start of a CLOSED period must be refused');
        } catch (DocumentPeriodLockedException $exception) {
            self::assertSame(PeriodLockRefusalCode::PeriodClosed, $exception->refusalCode);
        }

        self::assertSame(DocumentStatus::Posted, $this->freshStatus($invoice));
    }

    /**
     * The LAST day of a locked period — the mirror boundary. See the sibling test
     * above for why a string bind broke this class of date.
     */
    public function test_a_document_dated_exactly_on_period_end_is_refused(): void
    {
        $periodEnd = Carbon::parse('2026-09-30');
        $period = $this->vatPeriodFor($periodEnd, VatPeriodStatus::Closed);
        self::assertSame(
            '2026-09-30',
            $period->period_end->toDateString(),
            'Precondition: the document date really is the last day of the period',
        );

        $invoice = $this->postedInvoiceWithGl($periodEnd);

        try {
            $this->postingService->cancel($invoice, 'last day of a closed period', $this->user->id);
            self::fail('A document dated on period_end of a CLOSED period must be refused');
        } catch (DocumentPeriodLockedException $exception) {
            self::assertSame(PeriodLockRefusalCode::PeriodClosed, $exception->refusalCode);
        }

        self::assertSame(DocumentStatus::Posted, $this->freshStatus($invoice));
    }

    /**
     * The boundary fix must not over-reach in the other direction: the day BEFORE
     * `period_start` and the day AFTER `period_end` stay cancellable.
     */
    public function test_the_days_immediately_outside_a_locked_period_stay_cancellable(): void
    {
        // A single month, locked, with documents on either side of it.
        $this->vatPeriodFor(Carbon::parse('2026-09-15'), VatPeriodStatus::Filed);

        $dayBefore = $this->postedInvoiceWithGl(Carbon::parse('2026-08-31'));
        $dayAfter = $this->postedInvoiceWithGl(Carbon::parse('2026-10-01'));

        $this->postingService->cancel($dayBefore, 'day before', $this->user->id);
        $this->postingService->cancel($dayAfter, 'day after', $this->user->id);

        self::assertSame(DocumentStatus::Cancelled, $this->freshStatus($dayBefore));
        self::assertSame(DocumentStatus::Cancelled, $this->freshStatus($dayAfter));
    }

    // ----------------------------------------- purchase / supplier (AP) ---

    /**
     * R-c c2 DEFAULT: the refusal applies to purchase documents too.
     *
     * The justification is AP / trial-balance integrity, NOT output-VAT symmetry:
     * a supplier invoice's input VAT is not in the declaration at all today
     * (`EloquentVatDataRepository:42` reads invoice/credit_note/expense only —
     * `supplier_invoice` appears nowhere in Taxation). What it DOES carry is a GL
     * entry dated `document_date`
     * (`GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry():1996`), so
     * withdrawing it inside a period whose books are closed is a ledger-integrity
     * problem regardless of VAT. Refusing is also forward-compatible if F2/F3
     * later bring supplier invoices into the declaration.
     *
     * REVERSIBLE by the c2 seam — see
     * `VatPeriodCancellationGuard::refusalAppliesTo()`, including its warning not
     * to flip before F2's AP mirror exists.
     */
    public function test_cancelling_a_supplier_invoice_in_a_closed_period_is_refused(): void
    {
        $documentDate = Carbon::parse('2026-01-22');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Closed);

        $supplierInvoice = $this->postedSupplierInvoice($documentDate);

        try {
            $this->postingService->cancel($supplierInvoice, 'wrong supplier', $this->user->id);
            self::fail('Cancelling a supplier invoice in a CLOSED VAT period must be refused');
        } catch (DocumentPeriodLockedException $exception) {
            self::assertSame(PeriodLockRefusalCode::PeriodClosed, $exception->refusalCode);
        }

        $supplierInvoice->refresh();
        self::assertSame(DocumentStatus::Posted, $supplierInvoice->status);
        self::assertNull($supplierInvoice->cancelled_at);
        self::assertNull($supplierInvoice->cancellation_reason);

        // NOTE (taxation gate m-3): a "zero REVCAN entries" assertion would be
        // VACUOUS here. `AccountingService::reverseDocumentGl()` returns null for
        // any type other than Invoice/CreditNote (`:833`) and SupplierInvoice
        // takes cancel()'s non-fiscal branch, so no reversal is written on the
        // SUCCESS path either — the count is zero whatever the guard does. The
        // load-bearing assertions on this branch are the refusal itself and the
        // untouched document state above. The reversal-suppression claim is
        // proven on the sales-invoice cases, where a reversal genuinely would be
        // written.
    }

    public function test_cancelling_a_supplier_invoice_in_a_filed_period_is_refused(): void
    {
        $documentDate = Carbon::parse('2026-02-05');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Filed);

        $supplierInvoice = $this->postedSupplierInvoice($documentDate);

        try {
            $this->postingService->cancel($supplierInvoice, 'wrong supplier', $this->user->id);
            self::fail('Cancelling a supplier invoice in a FILED VAT period must be refused');
        } catch (DocumentPeriodLockedException $exception) {
            self::assertSame(PeriodLockRefusalCode::PeriodFiled, $exception->refusalCode);
        }

        self::assertSame(DocumentStatus::Posted, $this->freshStatus($supplierInvoice));
    }

    // ------------------------------------------------- overlap ordering ---

    /**
     * Taxation gate m-3 / GL gate m-3 — pins the FILED-first ordering.
     *
     * `vat_periods` is unique per `(company_id, period_start, period_end)` but NOT
     * per `country_code`, so two country-scoped period sets can cover the same
     * day with different statuses. The lookup's `orderByRaw` CASE resolves such an
     * overlap to the STRICTER reason, because the two codes carry different
     * remedies: CLOSED can be reopened, FILED never can. Without this test a
     * refactor to a plain `orderBy('status')` would silently downgrade FILED to
     * CLOSED ('CLOSED' sorts before 'FILED') and the UI would offer a reopen that
     * cannot happen.
     */
    public function test_an_overlapping_closed_and_filed_period_refuses_with_the_filed_code(): void
    {
        $documentDate = Carbon::parse('2026-10-14');

        // Two periods covering the same day, deliberately different spans so the
        // (company, start, end) unique key permits both. The CLOSED one is
        // inserted FIRST so natural row order would surface it.
        $this->vatPeriod(
            Carbon::parse('2026-10-01'),
            Carbon::parse('2026-10-31'),
            VatPeriodStatus::Closed,
        );
        $this->vatPeriod(
            Carbon::parse('2026-10-12'),
            Carbon::parse('2026-10-18'),
            VatPeriodStatus::Filed,
        );

        $invoice = $this->postedInvoiceWithGl($documentDate);

        try {
            $this->postingService->cancel($invoice, 'overlapping periods', $this->user->id);
            self::fail('An overlapping locked period must still refuse');
        } catch (DocumentPeriodLockedException $exception) {
            self::assertSame(
                PeriodLockRefusalCode::PeriodFiled,
                $exception->refusalCode,
                'FILED is the stricter reason and must win an overlap',
            );
            self::assertSame(VatPeriodStatus::Filed, $exception->periodStatus);
        }

        self::assertSame(DocumentStatus::Posted, $this->freshStatus($invoice));
    }

    // ------------------------------- types deliberately OUTSIDE the lock ---

    /**
     * Taxation gate I-4 (ruling adopted) — the lock covers DECLARATION- and
     * LEDGER-bearing types only, not literally every document type.
     *
     * These types post no journal entry and write no `document_tax_details` row,
     * so locking them would protect nothing while making them permanently
     * uncancellable the moment a lane routes their cancellation through
     * `DocumentPostingService::cancel()`. One case per excluded type so a future
     * widening of `refusalAppliesTo()` cannot pass silently.
     */
    #[DataProvider('provideTypesOutsideTheLock')]
    public function test_a_non_declaration_bearing_type_is_cancellable_in_a_filed_period(
        DocumentType $type,
    ): void {
        $documentDate = Carbon::parse('2026-11-12');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Filed);

        $document = $this->postedDocumentOfType($type, $documentDate);

        $this->postingService->cancel($document, 'not declaration-bearing', $this->user->id);

        self::assertSame(
            DocumentStatus::Cancelled,
            $this->freshStatus($document),
            $type->value.' posts no GL and no tax detail — the VAT period must not lock it',
        );
    }

    /**
     * @return iterable<string, array{DocumentType}>
     */
    public static function provideTypesOutsideTheLock(): iterable
    {
        yield 'quote' => [DocumentType::Quote];
        yield 'delivery note' => [DocumentType::DeliveryNote];
        yield 'return note' => [DocumentType::ReturnNote];
        yield 'purchase order' => [DocumentType::PurchaseOrder];
        yield 'purchase quote request' => [DocumentType::PurchaseQuoteRequest];
    }

    /**
     * GL re-gate I-5 — `Income` is LEDGER-bearing and must be locked.
     *
     * The first narrowing pass excluded it on the strength of the declaration
     * half of the criterion alone (`EloquentVatDataRepository:42` really does
     * exclude income). But the criterion is DISJUNCTIVE — reaches the ledger OR
     * the declaration — and the ledger half is true:
     * `GeneralLedgerService::createFromIncome()` (declared `:4056`) writes a
     * journal entry at `:4106`, and `IncomeService::post()` calls it
     * SYNCHRONOUSLY in-transaction (`:161`) right after flipping the document to
     * Posted (`:152`).
     *
     * Not exploitable today — no route cancels an Income
     * (`IncomeController::destroy` is Draft-only) — but this is precisely the
     * trigger the seam docblock anticipates: when an Income-cancel lane lands, a
     * FILED-period Income would withdraw with NO refusal and NO GL reversal
     * (`reverseDocumentGl()` returns null for non-Invoice/CreditNote), leaving
     * its cash and revenue legs standing forever.
     *
     * Service-level, like the supplier-invoice cases: there is no HTTP route.
     */
    public function test_cancelling_an_income_in_a_filed_period_is_refused(): void
    {
        $documentDate = Carbon::parse('2026-02-18');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Filed);

        $income = $this->postedDocumentOfType(DocumentType::Income, $documentDate);

        try {
            $this->postingService->cancel($income, 'income recorded in error', $this->user->id);
            self::fail('Cancelling an Income in a FILED VAT period must be refused');
        } catch (DocumentPeriodLockedException $exception) {
            self::assertSame(PeriodLockRefusalCode::PeriodFiled, $exception->refusalCode);
        }

        $income->refresh();
        self::assertSame(DocumentStatus::Posted, $income->status);
        self::assertNull($income->cancelled_at);
    }

    public function test_cancelling_an_income_in_a_closed_period_is_refused(): void
    {
        $documentDate = Carbon::parse('2026-02-19');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Closed);

        $income = $this->postedDocumentOfType(DocumentType::Income, $documentDate);

        try {
            $this->postingService->cancel($income, 'income recorded in error', $this->user->id);
            self::fail('Cancelling an Income in a CLOSED VAT period must be refused');
        } catch (DocumentPeriodLockedException $exception) {
            self::assertSame(PeriodLockRefusalCode::PeriodClosed, $exception->refusalCode);
        }

        self::assertSame(DocumentStatus::Posted, $this->freshStatus($income));
    }

    public function test_cancelling_an_income_in_an_open_period_still_works(): void
    {
        $documentDate = Carbon::parse('2026-02-20');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Open);

        $income = $this->postedDocumentOfType(DocumentType::Income, $documentDate);

        $this->postingService->cancel($income, 'income recorded in error', $this->user->id);

        self::assertSame(DocumentStatus::Cancelled, $this->freshStatus($income));
    }

    // ------------------------------------------------------ happy paths ---

    public function test_cancelling_a_sales_invoice_in_an_open_period_still_works(): void
    {
        $documentDate = Carbon::parse('2026-04-08');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Open);

        $invoice = $this->postedInvoiceWithGl($documentDate);

        $this->postingService->cancel($invoice, 'customer withdrew', $this->user->id);

        self::assertSame(DocumentStatus::Cancelled, $this->freshStatus($invoice));
        self::assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
                ->where('source_id', $invoice->id)
                ->count(),
            'An OPEN period must still reverse exactly as before this lane',
        );
    }

    public function test_cancelling_a_supplier_invoice_in_an_open_period_still_works(): void
    {
        $documentDate = Carbon::parse('2026-04-09');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Open);

        $supplierInvoice = $this->postedSupplierInvoice($documentDate);

        $this->postingService->cancel($supplierInvoice, 'duplicate entry', $this->user->id);

        self::assertSame(DocumentStatus::Cancelled, $this->freshStatus($supplierInvoice));
    }

    /**
     * Period-resolution semantics: NO `vat_periods` row covering the document
     * date means nothing has ever been closed or filed for that span, so there
     * is nothing to protect — the cancel proceeds. Any other reading would make
     * every document uncancellable on every tenant that has not started
     * declaring VAT yet (which is all of them at launch).
     */
    public function test_a_document_with_no_vat_period_row_at_all_is_still_cancellable(): void
    {
        $invoice = $this->postedInvoiceWithGl(Carbon::parse('2026-05-11'));
        self::assertSame(0, VatPeriod::query()->count(), 'Precondition: no periods exist');

        $this->postingService->cancel($invoice, 'no periods configured', $this->user->id);

        self::assertSame(DocumentStatus::Cancelled, $this->freshStatus($invoice));
    }

    /**
     * The lock is scoped to the document's OWN company: another company's closed
     * period must never block this company's cancel.
     */
    public function test_another_companys_closed_period_does_not_block_the_cancel(): void
    {
        $documentDate = Carbon::parse('2026-06-17');

        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company SARL',
            'tax_id' => 'TAX-OTHER-LOCK',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Filed, $otherCompany->id);

        $invoice = $this->postedInvoiceWithGl($documentDate);

        $this->postingService->cancel($invoice, 'other company period is irrelevant', $this->user->id);

        self::assertSame(DocumentStatus::Cancelled, $this->freshStatus($invoice));
    }

    /**
     * A period that does NOT cover the document's own date must not block it —
     * the lookup keys on `document_date`, the same date the GL entry and the VAT
     * declaration both use for this document.
     */
    public function test_a_closed_period_that_does_not_cover_the_document_date_does_not_block(): void
    {
        $this->vatPeriodFor(Carbon::parse('2026-01-15'), VatPeriodStatus::Filed);

        $invoice = $this->postedInvoiceWithGl(Carbon::parse('2026-07-15'));

        $this->postingService->cancel($invoice, 'different month', $this->user->id);

        self::assertSame(DocumentStatus::Cancelled, $this->freshStatus($invoice));
    }

    // ---------------------------------------------------------- HTTP 422 ---

    public function test_the_cancel_endpoint_returns_a_coded_422(): void
    {
        $documentDate = Carbon::parse('2026-01-18');
        $period = $this->vatPeriodFor($documentDate, VatPeriodStatus::Filed);

        $invoice = $this->postedInvoiceWithGl($documentDate);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", ['reason' => 'filed period']);

        $response->assertStatus(422);

        // The WHOLE envelope, not just the code — the FE renders the period label
        // in "ask your accountant to reopen <period>" and branches on the status.
        $response->assertJsonPath('error.code', PeriodLockRefusalCode::PeriodFiled->value);
        $response->assertJsonPath('error.document_number', $invoice->document_number);
        $response->assertJsonPath('error.period_label', $period->label);
        $response->assertJsonPath('error.period_status', VatPeriodStatus::Filed->value);

        $message = $response->json('error.message');
        self::assertIsString($message);
        self::assertNotSame('', $message);
        self::assertStringContainsString(
            $invoice->document_number,
            $message,
            'The message must name the document it refused',
        );
        self::assertStringContainsString(
            $period->label,
            $message,
            'The message must name the period that blocked it',
        );

        self::assertSame(DocumentStatus::Posted, $this->freshStatus($invoice));
    }

    // ------------------------------------------- can-cancel read model ---

    /**
     * GL gate I-3 — the read model must agree with the write path.
     *
     * Before this, `canCancelInvoice()` knew nothing about accounting periods, so
     * an invoice in a FILED period reported `can_cancel: true`, the FE rendered a
     * live Cancel button, and every click returned a 422. A filed declaration is
     * never reopened, so that button was a PERMANENT dead end.
     */
    public function test_can_cancel_reports_false_with_a_reason_code_for_a_filed_period(): void
    {
        $documentDate = Carbon::parse('2026-01-25');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Filed);

        $invoice = $this->postedInvoiceWithGl($documentDate);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/can-cancel");

        $response->assertOk();
        $response->assertJsonPath('data.can_cancel', false);
        $response->assertJsonPath('data.reason_code', PeriodLockRefusalCode::PeriodFiled->value);
    }

    public function test_can_cancel_reports_false_with_a_reason_code_for_a_closed_period(): void
    {
        $documentDate = Carbon::parse('2026-01-26');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Closed);

        $invoice = $this->postedInvoiceWithGl($documentDate);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/can-cancel");

        $response->assertOk();
        $response->assertJsonPath('data.can_cancel', false);
        $response->assertJsonPath('data.reason_code', PeriodLockRefusalCode::PeriodClosed->value);
    }

    public function test_can_cancel_stays_true_with_no_reason_code_in_an_open_period(): void
    {
        $documentDate = Carbon::parse('2026-01-27');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Open);

        $invoice = $this->postedInvoiceWithGl($documentDate);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/can-cancel");

        $response->assertOk();
        $response->assertJsonPath('data.can_cancel', true);
        $response->assertJsonPath('data.reason_code', null);
    }

    /**
     * The read model and the write path must never disagree: whatever
     * `can-cancel` reports as the blocker is exactly the code the cancel returns.
     */
    public function test_the_read_model_code_matches_the_code_the_cancel_endpoint_returns(): void
    {
        $documentDate = Carbon::parse('2026-01-28');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Filed);

        $invoice = $this->postedInvoiceWithGl($documentDate);

        $readModel = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/can-cancel");
        $writePath = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", ['reason' => 'filed period']);

        $writePath->assertStatus(422);
        self::assertSame(
            $writePath->json('error.code'),
            $readModel->json('data.reason_code'),
            'can-cancel must predict the exact refusal the cancel endpoint raises',
        );
    }

    // ------------------------------------------------------------ helpers ---

    private function freshStatus(Document $document): DocumentStatus
    {
        return $this->reload($document)->status;
    }

    private function reload(Document $document): Document
    {
        /** @var Document */
        return Document::query()->with('lines')->findOrFail($document->id);
    }

    /** The whole calendar month containing $date. */
    private function vatPeriodFor(Carbon $date, VatPeriodStatus $status, ?string $companyId = null): VatPeriod
    {
        return $this->vatPeriod(
            $date->copy()->startOfMonth(),
            $date->copy()->endOfMonth(),
            $status,
            $companyId,
        );
    }

    /** An explicit span — used by the overlap case, which needs two of them. */
    private function vatPeriod(
        Carbon $start,
        Carbon $end,
        VatPeriodStatus $status,
        ?string $companyId = null,
    ): VatPeriod {
        DB::table('countries')->insertOrIgnore([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'is_active' => true,
        ]);

        return VatPeriod::create([
            'company_id' => $companyId ?? $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => $start->format('F Y').' ('.$start->format('d').'-'.$end->format('d').')',
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'status' => $status,
        ]);
    }

    private function postedInvoiceWithGl(Carbon $documentDate): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-PERIOD-'.uniqid(),
            'document_date' => $documentDate,
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'balance_due' => '119.000',
            'currency' => 'TND',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $invoice->id,
            'product_id' => $this->product->id,
            'line_number' => 1,
            'description' => 'Doliprane 1000mg',
            'quantity' => '10',
            'unit_price' => '10.000',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);

        /** @var Document $fresh */
        $fresh = $invoice->fresh(['lines']);
        $this->accountingService->createInvoiceGLEntries($fresh);

        /** @var Document */
        return $fresh->fresh(['lines']);
    }

    /**
     * A minimal POSTED document of an arbitrary type, for the exclusion cases.
     * These types post no GL, so no accounting fixture is needed.
     */
    private function postedDocumentOfType(DocumentType $type, Carbon $documentDate): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => $type,
            'document_number' => $type->getPrefix().'-PERIOD-'.uniqid(),
            'document_date' => $documentDate,
            'status' => DocumentStatus::Posted,
            'subtotal' => '50.000',
            'tax_amount' => '9.500',
            'total' => '59.500',
            'balance_due' => '59.500',
            'currency' => 'TND',
        ]);

        /** @var Document */
        return $document->fresh(['lines']);
    }

    private function postedSupplierInvoice(Carbon $documentDate): Document
    {
        $supplierInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'document_number' => 'SI-PERIOD-'.uniqid(),
            'document_date' => $documentDate,
            'status' => DocumentStatus::Posted,
            'subtotal' => '200.000',
            'tax_amount' => '38.000',
            'total' => '238.000',
            'balance_due' => '238.000',
            'currency' => 'TND',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $supplierInvoice->id,
            'product_id' => $this->product->id,
            'line_number' => 1,
            'description' => 'Doliprane 1000mg (achat)',
            'quantity' => '40',
            'unit_price' => '5.000',
            'tax_rate' => '19.00',
            'line_total' => '200.000',
        ]);

        /** @var Document */
        return $supplierInvoice->fresh(['lines']);
    }
}
