<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Application\Services\Reports\AgedPayablesService;
use App\Modules\Accounting\Application\Services\Reports\AgedReceivablesService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\ArApOpeningService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W4-3 (P0) + W4-4 (P1) — AR/AP opening items.
 *
 * W4-3: `ArApOpeningService` mapped `document_type` to `DocumentType::Invoice` /
 * `CreditNote` for BOTH the AR and the AP batch — only the partner lookup differed.
 * An AP opening item was therefore a CUSTOMER invoice, and `PaymentController`
 * (which decides supplier-ness by `type === DocumentType::SupplierInvoice`) paid it
 * through the AR arm: `Dr 512 / Cr 411`, cash direction IN, the `401` debt untouched.
 *
 * W4-4: the AR/AP batches posted no GL at all, so `partners.receivable_balance` /
 * `payable_balance` — refreshed from POSTED journal lines carrying a partner
 * dimension — stayed 0.000 against real open items, and aged AP could not see an
 * opening supplier item at all.
 *
 * The fix pins the whole opening arc: AP openings are supplier-side documents with
 * their own `HIST-SINV` sequence, every opening document posts its own historical
 * journal entry against the opening-balance counterpart with the control leg
 * partner-tagged, and the partner sub-ledger is refreshed at post.
 *
 * docs/handoff/PLAYWRIGHT-first-tenant-campaign-wave4-critical-path-2026-08-24.md §W4-3 / §W4-4
 */
final class ArApOpeningLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-25 09:00:00'));

        $this->tenant = Tenant::create([
            'name' => 'W43 Opening Tenant',
            'slug' => 'w43-opening-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parabio Tunisie',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Admin',
            'email' => 'w43-admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CUST-001',
            'name' => 'Nadia Chaabane',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SUPP-001',
            'name' => 'Laboratoires Méditerranée SA',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- W4-3 ----

    public function test_an_ap_opening_item_is_a_supplier_invoice_with_its_own_hist_sinv_sequence(): void
    {
        $this->postApBatch('500.000');

        $document = Document::query()
            ->where('company_id', $this->company->id)
            ->where('partner_id', $this->supplier->id)
            ->firstOrFail();

        self::assertSame(
            DocumentType::SupplierInvoice,
            $document->type,
            'An AP opening item must be a supplier-side document — PaymentController branches on exactly this.'
        );
        self::assertStringStartsWith(
            'HIST-SINV-',
            (string) $document->document_number,
            'AP openings must not share the customer HIST-INV sequence.'
        );
        self::assertTrue($document->is_historical);
    }

    public function test_an_ap_opening_credit_note_is_a_supplier_credit_note(): void
    {
        $this->postApBatch('120.000', documentType: 'credit_note');

        $document = Document::query()
            ->where('company_id', $this->company->id)
            ->where('partner_id', $this->supplier->id)
            ->firstOrFail();

        self::assertSame(DocumentType::SupplierCreditNote, $document->type);
        self::assertStringStartsWith('HIST-SCN-', (string) $document->document_number);
    }

    public function test_an_ar_opening_item_stays_a_customer_invoice(): void
    {
        $this->postArBatch('150.000');

        $document = Document::query()
            ->where('company_id', $this->company->id)
            ->where('partner_id', $this->customer->id)
            ->firstOrFail();

        self::assertSame(DocumentType::Invoice, $document->type);
        self::assertStringStartsWith('HIST-INV-', (string) $document->document_number);
    }

    // ------------------------------------------------------------- W4-4 ----

    public function test_an_ar_opening_posts_dr_receivable_cr_opening_equity_with_the_partner_dimension(): void
    {
        $this->postArBatch('150.000');

        $document = Document::query()->where('partner_id', $this->customer->id)->firstOrFail();
        $entry = $this->openingEntryFor($document);

        self::assertSame('invoice', $entry->source_type);
        self::assertSame(JournalEntryStatus::Posted, $entry->status);
        self::assertTrue((bool) $entry->is_historical, 'An opening entry must stay out of the fiscal hash chain.');

        $receivable = $this->accountFor(SystemAccountPurpose::CustomerReceivable);
        $counterpart = $this->accountFor(SystemAccountPurpose::OpeningBalanceEquity);

        $debit = $this->lineOn($entry, $receivable->id);
        self::assertSame('150.000', $debit->debit);
        self::assertSame('0.000', $debit->credit);
        self::assertSame(
            $this->customer->id,
            $debit->partner_id,
            'The control leg carries the partner dimension — that is what the sub-ledger reads.'
        );

        $credit = $this->lineOn($entry, $counterpart->id);
        self::assertSame('150.000', $credit->credit);
        self::assertNull($credit->partner_id);
    }

    public function test_an_ap_opening_posts_dr_opening_equity_cr_payable_with_the_partner_dimension(): void
    {
        $this->postApBatch('500.000');

        $document = Document::query()->where('partner_id', $this->supplier->id)->firstOrFail();
        $entry = $this->openingEntryFor($document);

        self::assertSame(
            'supplier_invoice',
            $entry->source_type,
            "PaymentController's Cr-401 precondition looks for exactly this source_type."
        );
        self::assertSame(JournalEntryStatus::Posted, $entry->status);

        $payable = $this->accountFor(SystemAccountPurpose::SupplierPayable);
        $counterpart = $this->accountFor(SystemAccountPurpose::OpeningBalanceEquity);

        $credit = $this->lineOn($entry, $payable->id);
        self::assertSame('500.000', $credit->credit);
        self::assertSame('0.000', $credit->debit);
        self::assertSame($this->supplier->id, $credit->partner_id);

        $debit = $this->lineOn($entry, $counterpart->id);
        self::assertSame('500.000', $debit->debit);
        self::assertNull($debit->partner_id);
    }

    public function test_the_partner_pages_read_the_opening_balances(): void
    {
        $this->postArBatch('150.000');
        $this->postApBatch('500.000');

        self::assertSame('150.000', $this->customer->refresh()->receivable_balance);
        self::assertSame('500.000', $this->supplier->refresh()->payable_balance);
    }

    public function test_the_opening_entry_carries_the_open_amount_not_the_original_total(): void
    {
        // A migrated invoice of 200.000 that the previous system already collected
        // 50.000 against: only the 150.000 still owed belongs in the cutover GL.
        $this->postArBatch(openAmount: '150.000', total: '200.000');

        $document = Document::query()->where('partner_id', $this->customer->id)->firstOrFail();
        $entry = $this->openingEntryFor($document);

        $debit = $this->lineOn($entry, $this->accountFor(SystemAccountPurpose::CustomerReceivable)->id);
        self::assertSame('150.000', $debit->debit);
        self::assertSame('150.000', $this->customer->refresh()->receivable_balance);
    }

    public function test_aged_reports_list_the_opening_items_on_the_correct_side(): void
    {
        $this->postArBatch('150.000');
        $this->postApBatch('500.000');

        $receivables = app(AgedReceivablesService::class)->generate($this->company->id, Carbon::parse('2026-08-25'));
        self::assertSame('150.0000', $receivables->grand_total, 'The AR opening must age as a receivable.');

        $payables = app(AgedPayablesService::class)->generate($this->company->id, Carbon::parse('2026-08-25'));
        self::assertSame('500.0000', $payables->grand_total, 'The AP opening must age as a payable.');
    }

    // ------------------------------------------------------------ helpers ---

    private function accountFor(SystemAccountPurpose $purpose): Account
    {
        return Account::findByPurposeOrFail($this->company->id, $purpose);
    }

    private function openingEntryFor(Document $document): JournalEntry
    {
        $entry = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_id', $document->id)
            ->with('lines')
            ->first();

        self::assertNotNull(
            $entry,
            "No journal entry was posted for opening document {$document->document_number} — the sub-ledger cannot see it."
        );

        return $entry;
    }

    private function lineOn(JournalEntry $entry, string $accountId): JournalLine
    {
        $line = $entry->lines->firstWhere('account_id', $accountId);

        self::assertNotNull($line, "Opening entry {$entry->entry_number} has no line on account {$accountId}.");

        return $line;
    }

    private function postArBatch(string $openAmount, ?string $total = null, string $documentType = 'invoice'): void
    {
        $this->postBatch(
            OpeningBatchType::ArOpenItems,
            'CUST-001',
            $openAmount,
            $total ?? $openAmount,
            $documentType,
        );
    }

    private function postApBatch(string $openAmount, ?string $total = null, string $documentType = 'invoice'): void
    {
        $this->postBatch(
            OpeningBatchType::ApOpenItems,
            'SUPP-001',
            $openAmount,
            $total ?? $openAmount,
            $documentType,
        );
    }

    private function postBatch(
        OpeningBatchType $type,
        string $partnerCode,
        string $openAmount,
        string $total,
        string $documentType,
    ): void {
        $batchService = app(OpeningBalanceBatchService::class);

        /** @var OpeningBalanceBatch $batch */
        $batch = $batchService->createBatch(
            $this->company,
            $type,
            Carbon::parse('2026-08-25'),
            $type->value.'-'.uniqid(),
            $this->user->id,
            'phpunit',
        );

        $batchService->addImportRows($batch, [[
            'partner_code' => $partnerCode,
            'external_invoice_number' => 'LEG-'.strtoupper(substr($partnerCode, 0, 4)),
            'document_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'total' => $total,
            'open_amount' => $openAmount,
            'document_type' => $documentType,
            'currency' => 'TND',
            'notes' => null,
        ]]);

        $service = app(ArApOpeningService::class);
        $service->validateBatch($batch->refresh());
        $service->postBatch($batch->refresh(), $this->user->id);
    }
}
