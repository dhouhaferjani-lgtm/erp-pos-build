<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private Partner $customer;

    private Document $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.view', 'payments.create', 'payments.allocate']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-2025-0001',
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '1000.00',
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00',
            'currency' => 'TND',
        ]);
    }

    public function test_can_list_payments(): void
    {
        Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
            'reference' => 'PMT-001',
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/payments');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_create_cash_payment(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'payment_date' => now()->toDateString(),
            'reference' => 'RCT-001',
            'notes' => 'Partial payment',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.amount', '500.000');
        $response->assertJsonPath('data.status', 'completed');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ingress precision — these bind to the REAL PaymentController::store()
    // inline validator (app/Modules/Treasury/Presentation/Controllers/
    // PaymentController.php:125 amount, :138 withholding_rate) via a true HTTP
    // request, so they fail if a production scale is changed to the wrong value.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_store_rejects_over_precise_amount(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '100.1234', // 4 decimals — over the money scale-3 ceiling
            'payment_date' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('amount', $response->json('error.errors') ?? []);
    }

    public function test_store_rejects_over_precise_withholding_rate(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '100.000',
            'payment_date' => now()->toDateString(),
            'withholding_enabled' => true,
            'withholding_rate' => '0.12345', // 5 decimals — over the decimal(5,4) ceiling
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('withholding_rate', $response->json('error.errors') ?? []);
    }

    public function test_store_accepts_4_decimal_withholding_rate(): void
    {
        // decimal(5,4) is a 0–1 fraction; real Tunisian rates like 0.015 (1.5%)
        // and 4-dp fractions must be accepted.
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '100.000',
            'payment_date' => now()->toDateString(),
            'withholding_enabled' => true,
            'withholding_rate' => '0.0150', // 4 decimals — within the decimal(5,4) ceiling
        ]);

        // The over-precision regex must NOT be the reason for any failure.
        $errors = $response->json('error.errors') ?? [];
        $this->assertArrayNotHasKey(
            'withholding_rate',
            $errors,
            'withholding_rate=0.0150 must pass the scale-4 ceiling: '
            .json_encode($errors)
        );
    }

    public function test_can_create_payment_with_allocation(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '1190.00',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '1190.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.amount', '1190.000');
        $response->assertJsonCount(1, 'data.allocations');
    }

    public function test_payment_updates_invoice_balance(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '500.00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $this->invoice->refresh();
        $this->assertEquals('690.000', $this->invoice->balance_due);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Supplier-invoice payment (C4 — supersedes the R-1 backwards baseline).
    //
    // Paying a posted supplier_invoice must:
    //   - post a `supplier_payment` JE: Dr SupplierPayable (401, partner-tagged)
    //     / Cr Bank (partner_id null), via the canonical hash-chained path;
    //   - DECREASE the repository balance (cash leaves to pay the supplier);
    //   - REDUCE payable_balance toward 0 (never < 0 — M-5 CHECK);
    //   - NOT post a `customer_payment` entry (supplier is AP, not AR);
    //   - transition the supplier_invoice to Paid when fully paid.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Seed a Posted supplier_invoice with an outstanding Cr 401 opening so the
     * supplier's payable_balance > 0. The opening 401 credit is posted via the
     * canonical hash-chained GL path so verifyChain holds and refreshPartnerBalance
     * (posted-only) sees it. Wrapped in a committing DB::transaction so its
     * afterCommit posting fires under RefreshDatabase (same nested-commit pattern
     * the controller relies on).
     */
    private function postedSupplierInvoice(Partner $supplier, string $total): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::SupplierInvoice,
            'document_number' => 'SI-2026-'.substr(md5($total.$supplier->id), 0, 6),
            'partner_id' => $supplier->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'EUR',
        ]);

        $expenseAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchaseExpenses);
        $user = $this->user;
        $companyId = $this->company->id;

        DB::transaction(function () use ($companyId, $supplier, $invoice, $total, $expenseAccount, $user): void {
            app(GeneralLedgerService::class)->createSupplierInvoiceJournalEntry(
                companyId: $companyId,
                partnerId: $supplier->id,
                invoiceId: $invoice->id,
                totalAmount: $total,
                netAmount: $total,
                vatAmount: '0.000',
                expenseAccountId: $expenseAccount->id,
                date: new \DateTimeImmutable('now'),
                user: $user,
                description: 'Opening supplier payable',
                currencyCode: 'EUR',
            );
        });

        return $invoice;
    }

    /**
     * @return array{0: Account, 1: PaymentRepository, 2: Partner}
     */
    private function supplierWithBankRepository(string $balance): array
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $bankAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);

        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK-AP',
            'name' => 'AP Bank',
            'type' => RepositoryType::BankAccount,
            'balance' => $balance,
            'gl_account_id' => $bankAccount->id,
            'is_active' => true,
        ]);

        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Supplier Corp',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        return [$bankAccount, $repository, $supplier];
    }

    public function test_supplier_invoice_payment_clears_401_and_reduces_payable_balance(): void
    {
        [$bankAccount, $repository, $supplier] = $this->supplierWithBankRepository('1000.00');
        $payableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);

        $supplierInvoice = $this->postedSupplierInvoice($supplier, '600.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $repository->id,
            'amount' => '600.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $supplierInvoice->id,
                    'amount' => '600.00',
                ],
            ],
        ]);

        $response->assertCreated();

        $payment = Payment::query()
            ->where('partner_id', $supplier->id)
            ->where('amount', '600.000')
            ->firstOrFail();

        $this->assertEquals(PaymentType::DocumentPayment, $payment->payment_type);

        // A supplier_payment JE exists (source_id = payment.id), Posted, chained.
        $journalEntry = JournalEntry::query()
            ->where('source_type', 'supplier_payment')
            ->where('source_id', $payment->id)
            ->with('lines')
            ->firstOrFail();
        $this->assertEquals(JournalEntryStatus::Posted, $journalEntry->status);

        // Dr 401 = amount, partner-tagged to the supplier.
        $payableLine = $journalEntry->lines->firstWhere('account_id', $payableAccount->id);
        $this->assertNotNull($payableLine);
        $this->assertEquals('600.000', $payableLine->debit);
        $this->assertEquals('0.000', $payableLine->credit);
        $this->assertEquals($supplier->id, $payableLine->partner_id);

        // Cr Bank = amount, no partner.
        $bankLine = $journalEntry->lines->firstWhere('account_id', $bankAccount->id);
        $this->assertNotNull($bankLine);
        $this->assertNull($bankLine->partner_id);
        $this->assertEquals('0.000', $bankLine->debit);
        $this->assertEquals('600.000', $bankLine->credit);

        // NO customer_payment entry for this payment.
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'customer_payment',
            'source_id' => $payment->id,
        ]);

        // Cash OUT: repository decreased 1000 → 400.
        $repository->refresh();
        $this->assertEquals('400.000', $repository->balance);

        // payable_balance cleared to 0 and never negative.
        $supplier->refresh();
        $this->assertEquals('0.000', $supplier->payable_balance);
        $this->assertStringStartsNotWith('-', (string) ($supplier->payable_balance ?? '0'));

        // Full payment transitions the supplier_invoice to Paid.
        $supplierInvoice->refresh();
        $this->assertEquals('0.000', $supplierInvoice->balance_due);
        $this->assertEquals(DocumentStatus::Paid, $supplierInvoice->status);

        // Hash chain intact.
        $this->assertTrue(app(GeneralLedgerHashService::class)->verifyChain($this->company->id));
    }

    public function test_partial_supplier_invoice_payment_reduces_payable_and_keeps_posted(): void
    {
        [, $repository, $supplier] = $this->supplierWithBankRepository('1000.00');

        $supplierInvoice = $this->postedSupplierInvoice($supplier, '600.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $repository->id,
            'amount' => '300.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $supplierInvoice->id,
                    'amount' => '300.00',
                ],
            ],
        ]);

        $response->assertCreated();

        // Cash out: 1000 → 700.
        $repository->refresh();
        $this->assertEquals('700.000', $repository->balance);

        // payable reflects the remaining half.
        $supplier->refresh();
        $this->assertEquals('300.000', $supplier->payable_balance);

        // Invoice stays Posted (not Paid) with the remaining balance.
        $supplierInvoice->refresh();
        $this->assertEquals('300.000', $supplierInvoice->balance_due);
        $this->assertEquals(DocumentStatus::Posted, $supplierInvoice->status);
    }

    public function test_supplier_invoice_overpayment_is_rejected(): void
    {
        [, $repository, $supplier] = $this->supplierWithBankRepository('1000.00');

        $supplierInvoice = $this->postedSupplierInvoice($supplier, '600.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $repository->id,
            'amount' => '601.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $supplierInvoice->id,
                    'amount' => '601.00', // exceeds the invoice's 600 outstanding balance
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'SUPPLIER_PAYMENT_EXCEEDS_PAYABLE');

        // Nothing moved: no cash out, no negative payable, invoice untouched.
        $repository->refresh();
        $this->assertEquals('1000.000', $repository->balance);

        $supplier->refresh();
        $this->assertStringStartsNotWith('-', (string) ($supplier->payable_balance ?? '0'));

        $supplierInvoice->refresh();
        $this->assertEquals('600.000', $supplierInvoice->balance_due);
        $this->assertEquals(DocumentStatus::Posted, $supplierInvoice->status);

        // No payment was created and no supplier_payment JE exists for this supplier
        // (FIX E — the previous assertion keyed on supplierInvoice->id was vacuous,
        // since supplier_payment.source_id is always the payment id).
        $this->assertDatabaseMissing('payments', ['partner_id' => $supplier->id]);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'supplier_payment')->count());
    }

    public function test_payment_mixing_supplier_and_customer_allocations_is_rejected(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $bankAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);
        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK-MIX',
            'name' => 'Mix Bank',
            'type' => RepositoryType::BankAccount,
            'balance' => '1000.00',
            'gl_account_id' => $bankAccount->id,
            'is_active' => true,
        ]);

        // One partner that holds BOTH an AR invoice and an AP supplier invoice, so
        // the cross-partner guard passes and the mixed-type guard is what fires.
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Dual Partner',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $supplierInvoice = $this->postedSupplierInvoice($partner, '600.000');

        $customerInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-MIX-0001',
            'partner_id' => $partner->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'balance_due' => '100.00',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $partner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $repository->id,
            'amount' => '200.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $supplierInvoice->id, 'amount' => '100.00'],
                ['document_id' => $customerInvoice->id, 'amount' => '100.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'MIXED_ALLOCATION_TYPES');

        // Nothing posted.
        $this->assertDatabaseMissing('payments', ['partner_id' => $partner->id]);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'supplier_payment')->count());
    }

    public function test_supplier_payment_requires_ledgered_repository(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Supplier Corp',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        // Repository WITHOUT a ledger account — cash would move with no 401 entry.
        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-NOLEDGER',
            'name' => 'Unledgered Cash',
            'type' => RepositoryType::CashRegister,
            'balance' => '1000.00',
            'gl_account_id' => null,
            'is_active' => true,
        ]);

        $supplierInvoice = $this->postedSupplierInvoice($supplier, '600.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $repository->id,
            'amount' => '600.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $supplierInvoice->id, 'amount' => '600.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'SUPPLIER_PAYMENT_REQUIRES_LEDGERED_REPOSITORY');

        // No cash moved, no payment, no supplier_payment JE.
        $repository->refresh();
        $this->assertEquals('1000.000', $repository->balance);
        $this->assertDatabaseMissing('payments', ['partner_id' => $supplier->id]);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'supplier_payment')->count());
    }

    public function test_split_payment_rejects_supplier_invoice(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Supplier Corp',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $supplierInvoice = $this->postedSupplierInvoice($supplier, '600.000');

        // MultiPaymentController::createSplitPayment is NOT supplier-aware → must reject.
        $response = $this->actingAs($this->user)->postJson("/api/v1/documents/{$supplierInvoice->id}/split-payment", [
            'splits' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => '300.00'],
                ['payment_method_id' => $this->cashMethod->id, 'amount' => '300.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'SUPPLIER_INVOICE_NOT_PAYABLE_HERE');
    }

    public function test_smart_apply_allocation_rejects_supplier_invoice(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Supplier Corp',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $supplierInvoice = $this->postedSupplierInvoice($supplier, '600.000');

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '600.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);

        // SmartPaymentController::applyAllocation (manual) routes through
        // PaymentAllocationService, which is NOT supplier-aware → must reject.
        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'manual',
            'manual_allocations' => [
                ['document_id' => $supplierInvoice->id, 'amount' => '600.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'SUPPLIER_INVOICE_NOT_PAYABLE_HERE');

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'supplier_payment')->count());
    }

    public function test_multiline_payment_rejects_supplier_invoice(): void
    {
        [, $repository, $supplier] = $this->supplierWithBankRepository('1000.00');

        $supplierInvoice = $this->postedSupplierInvoice($supplier, '600.000');

        // Multi-line shape (the `payments` key routes store() → storeMultiple), which
        // is NOT supplier-aware. Must reject before any payment/cash/GL.
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $supplier->id,
            'document_id' => $supplierInvoice->id,
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'repository_id' => $repository->id, 'amount' => '300.00'],
                ['payment_method_id' => $this->cashMethod->id, 'repository_id' => $repository->id, 'amount' => '300.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'SUPPLIER_INVOICE_NOT_PAYABLE_VIA_MULTILINE');

        // No payment, no cash movement, no GL of either direction.
        $repository->refresh();
        $this->assertEquals('1000.000', $repository->balance);
        $this->assertDatabaseMissing('payments', ['partner_id' => $supplier->id]);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'supplier_payment')->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'customer_payment')->count());
    }

    public function test_multiline_customer_payment_still_works(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $bankAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);
        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK-AR',
            'name' => 'AR Bank',
            'type' => RepositoryType::BankAccount,
            'balance' => '0.00',
            'gl_account_id' => $bankAccount->id,
            'is_active' => true,
        ]);

        // Two payment lines fully paying the AR invoice (1190) via the multi-line path.
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'document_id' => $this->invoice->id,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'repository_id' => $repository->id, 'amount' => '600.00'],
                ['payment_method_id' => $this->cashMethod->id, 'repository_id' => $repository->id, 'amount' => '590.00'],
            ],
        ]);

        $response->assertStatus(201);

        // Cash IN: 0 → 1190.
        $repository->refresh();
        $this->assertEquals('1190.000', $repository->balance);

        // Invoice fully paid.
        $this->invoice->refresh();
        $this->assertEquals('0.000', $this->invoice->balance_due);
        $this->assertEquals(DocumentStatus::Paid, $this->invoice->status);

        // Customer GL posted, never the supplier direction.
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'supplier_payment')->count());
        $this->assertGreaterThan(0, JournalEntry::query()->where('source_type', 'customer_payment')->count());
    }

    public function test_full_payment_marks_invoice_as_paid(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '1190.00',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '1190.00',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $this->invoice->refresh();
        $this->assertEquals('0.000', $this->invoice->balance_due);
        $this->assertEquals(DocumentStatus::Paid, $this->invoice->status);
    }

    public function test_overpayment_caps_allocation_and_creates_excess(): void
    {
        // Invoice has balance_due of 1190 (1000 + 190 tax), but we request to allocate 2000
        // The system should cap the allocation at 1190 (invoice balance)
        // and allow the excess (810) to be treated as customer advance

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '2000.00',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '2000.00', // More than balance_due - will be capped
                ],
            ],
        ]);

        // Payment should succeed
        $response->assertStatus(201);

        // Allocation should be capped at invoice balance (1190)
        $this->assertDatabaseHas('payment_allocations', [
            'document_id' => $this->invoice->id,
            'amount' => '1190.0000', // Capped at invoice balance
        ]);

        // Invoice should be fully paid
        $this->invoice->refresh();
        $this->assertEquals('0.000', $this->invoice->balance_due);
        $this->assertEquals(DocumentStatus::Paid, $this->invoice->status);
    }

    public function test_can_filter_payments_by_partner(): void
    {
        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Corp',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);

        Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $otherPartner->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '300.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/payments?partner_id='.$this->customer->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_view_single_payment(): void
    {
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
            'reference' => 'PMT-001',
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/payments/{$payment->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.reference', 'PMT-001');
        $response->assertJsonPath('data.amount', '500.000');
    }

    public function test_unauthorized_user_cannot_create_payment(): void
    {
        $this->user->revokePermissionTo('payments.create');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'payment_date' => now()->toDateString(),
        ]);

        $response->assertStatus(403);
    }

    public function test_can_allocate_payment_to_multiple_invoices(): void
    {
        $invoice2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-2025-0002',
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '500.00',
            'tax_amount' => '95.00',
            'total' => '595.00',
            'balance_due' => '595.00',
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '1785.00', // Sum of both invoices
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '1190.00',
                ],
                [
                    'document_id' => $invoice2->id,
                    'amount' => '595.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(2, 'data.allocations');

        $this->invoice->refresh();
        $invoice2->refresh();

        $this->assertEquals('0.000', $this->invoice->balance_due);
        $this->assertEquals('0.000', $invoice2->balance_due);
    }

    public function test_allocation_amount_cannot_exceed_payment_amount(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.00',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                [
                    'document_id' => $this->invoice->id,
                    'amount' => '1000.00', // More than payment amount
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ALLOCATION_EXCEEDS_PAYMENT');
    }
}
