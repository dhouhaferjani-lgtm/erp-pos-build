<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
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
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression: admin payments must post a GL journal entry and refresh AR/AP
 * subledger balances. The bug gated GL posting on the dead legacy
 * payment_repositories.account_id (always NULL) instead of the canonical
 * gl_account_id, so the journal entry was silently skipped and
 * receivable_balance / payable_balance never refreshed.
 */
class PaymentGlPostingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $receivableAccount;

    private Account $revenueAccount;

    private PaymentMethod $paymentMethod;

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
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.create', 'payments.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);
        $this->receivableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::CustomerReceivable);
        $this->revenueAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::ProductRevenue);

        $this->paymentMethod = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
            'is_active' => true,
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => false,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);
    }

    /** @test */
    public function customer_payment_posts_gl_and_refreshes_receivable_balance(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);

        // Open receivable: Dr 411 (partner) 300 / Cr revenue. Drives receivable_balance.
        $this->postOpeningReceivable($partner, '300.000');

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-AR-001',
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '300.000',
            'tax_amount' => '0.000',
            'total' => '300.000',
            'balance_due' => '300.000',
            'currency' => 'TND',
        ]);

        $repository = $this->makeLedgeredRepository();

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '120.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-AR-001',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '120.000'],
            ],
        ]);

        $response->assertCreated();

        $paymentId = $response->json('data.id');

        // A posted customer_payment journal entry must exist for this payment.
        $entry = JournalEntry::query()
            ->where('source_type', 'customer_payment')
            ->where('source_id', $paymentId)
            ->first();

        $this->assertNotNull($entry, 'Customer payment must create a journal entry.');
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        // The payment row must link to its journal entry.
        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'journal_entry_id' => $entry->id,
        ]);

        // receivable_balance must drop from 300 to 180.
        $partner->refresh();
        $this->assertSame('180.000', $partner->receivable_balance);
    }

    /** @test */
    public function supplier_payment_posts_gl_and_refreshes_payable_balance(): void
    {
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Supplier',
            'type' => PartnerType::Supplier,
        ]);

        $expenseAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchaseExpenses);

        $supplierInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'document_number' => 'SINV-001',
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '100.000',
            'currency' => 'TND',
        ]);

        // Posted supplier_invoice JE (Cr 401, partner-tagged) — required by the
        // store() guard before a supplier payment is accepted.
        app(GeneralLedgerService::class)->createSupplierInvoiceJournalEntry(
            companyId: $this->company->id,
            partnerId: $supplier->id,
            invoiceId: $supplierInvoice->id,
            totalAmount: '100.000',
            netAmount: '100.000',
            vatAmount: '0.000',
            expenseAccountId: $expenseAccount->id,
            date: now(),
            user: $this->user,
            currencyCode: 'TND',
        );

        $supplier->refresh();
        $this->assertSame('100.000', $supplier->payable_balance);

        $repository = $this->makeLedgeredRepository();

        // W-5b Option B: a supplier payment moves cash OUT of the repository
        // (MovementDirection::Out), and a cash_register defaults
        // allow_negative = false — fund it first so this GL-posting
        // assertion isn't entangled with the (unrelated) balance-sufficiency
        // guard. Mirrors VendorPrepaymentRefundTest's fix for the same
        // latent gap.
        $this->fundRepository($repository, '100.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $supplier->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '100.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-AP-001',
            'allocations' => [
                ['document_id' => $supplierInvoice->id, 'amount' => '100.000'],
            ],
        ]);

        $response->assertCreated();

        $paymentId = $response->json('data.id');

        $entry = JournalEntry::query()
            ->where('source_type', 'supplier_payment')
            ->where('source_id', $paymentId)
            ->first();

        $this->assertNotNull($entry, 'Supplier payment must create a journal entry.');
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        $supplier->refresh();
        $this->assertSame('0.000', $supplier->payable_balance);
    }

    /** @test */
    public function payment_with_repository_missing_gl_account_is_rejected(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-AR-002',
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '50.000',
            'tax_amount' => '0.000',
            'total' => '50.000',
            'balance_due' => '50.000',
            'currency' => 'TND',
        ]);

        // Repository with NO gl_account_id — posting would silently skip GL.
        $repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-NOGL',
            'name' => 'Unledgered Cash',
            'type' => RepositoryType::CashRegister,
            'balance' => '0.000',
            'gl_account_id' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '50.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-NOGL',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '50.000'],
            ],
        ]);

        $response->assertStatus(422);
    }

    private function makeLedgeredRepository(): PaymentRepository
    {
        return PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-'.substr((string) Str::uuid(), 0, 8),
            'name' => 'Main Cash',
            'type' => RepositoryType::CashRegister,
            'balance' => '0.000',
            // Canonical GL account set; legacy account_id intentionally left NULL.
            'gl_account_id' => $this->cashAccount->id,
            'account_id' => null,
            'is_active' => true,
        ]);
    }

    /**
     * Fund a repository through the movement port (mirrors
     * PaymentRepositorySeeder::recordOpeningBalance()) — `balance` is
     * port-managed and NOT fillable, so a plain create()/update() with a
     * 'balance' key is silently dropped, and W-5b Option B now enforces that
     * an outflow can't take a cash_register below zero.
     *
     * @param  numeric-string  $amount
     */
    private function fundRepository(PaymentRepository $repository, string $amount): void
    {
        DB::transaction(fn () => app(TreasuryMovementServiceInterface::class)->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $repository->tenant_id,
            companyId: $repository->company_id,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $repository->currency,
            sourceType: MovementSourceType::OpeningBalance,
            sourceId: $repository->id,
            idempotencyLeg: 'opening',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: 'Test fixture opening balance',
            allowWhileFrozen: false,
        )));
        $repository->refresh();
    }

    private function postOpeningReceivable(Partner $partner, string $amount): void
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-OPEN-AR-'.substr((string) Str::uuid(), 0, 8),
            'entry_date' => now(),
            'description' => 'Opening receivable',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'test_receivable',
            'source_id' => (string) Str::uuid(),
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->receivableAccount->id,
            'partner_id' => $partner->id,
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Opening receivable',
            'line_order' => 0,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
            'partner_id' => null,
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Opening revenue',
            'line_order' => 1,
        ]);

        app(PartnerBalanceService::class)
            ->refreshPartnerBalance($this->company->id, $partner->id);
    }
}
