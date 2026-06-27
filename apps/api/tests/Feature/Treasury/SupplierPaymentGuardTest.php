<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\SupplierInvoicePostingService;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * B1 guard tests: supplier payments must target a POSTED invoice with a real Cr-401 JE.
 */
final class SupplierPaymentGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private Partner $supplier;

    private Account $bankAccount;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'B1 Guard Test Tenant',
            'slug' => 'b1-guard-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B1 Guard Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->bankAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B1 Test User',
            'email' => 'b1user@example.com',
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
            'code' => 'BANK-AP',
            'name' => 'Bank',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'B1 Test Supplier',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $this->repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'REPO-BANK',
            'name' => 'AP Bank Repo',
            'type' => RepositoryType::BankAccount,
            'balance' => '5000.000',
            'account_id' => $this->bankAccount->id,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // B1a: paying a DRAFT supplier invoice must be rejected with 422.
    // =========================================================================

    public function test_paying_draft_supplier_invoice_is_rejected_422(): void
    {
        $draftInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,   // <-- DRAFT, not Posted
            'document_number' => 'SI-B1-DRAFT-'.Str::upper(Str::random(4)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '100.00',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $draftInvoice->id, 'amount' => '100.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'SUPPLIER_INVOICE_NOT_POSTED');

        // No side effects: no payment, no JE, no allocation.
        $this->assertDatabaseMissing('payments', ['partner_id' => $this->supplier->id]);
        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'supplier_payment']);
        $this->assertDatabaseMissing('payment_allocations', ['document_id' => $draftInvoice->id]);
    }

    // =========================================================================
    // B1a: paying a POSTED invoice with NO journal entry must be rejected with 422.
    // =========================================================================

    public function test_paying_posted_supplier_invoice_without_journal_entry_is_rejected_422(): void
    {
        // Manually create a Posted invoice without going through SupplierInvoicePostingService
        // (so no Cr-401 JE exists).
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Posted,  // Posted but no JE
            'document_number' => 'SI-B1-NOJETST-'.Str::upper(Str::random(4)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '200.000',
            'tax_amount' => '0.000',
            'total' => '200.000',
            'balance_due' => '200.000',
        ]);

        // Confirm no JE exists for this invoice.
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'supplier_invoice',
            'source_id' => $invoice->id,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '200.00',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '200.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'SUPPLIER_INVOICE_NOT_POSTED');

        // No side effects.
        $this->assertDatabaseMissing('payments', ['partner_id' => $this->supplier->id]);
    }

    // =========================================================================
    // B1a: paying a POSTED invoice WITH a journal entry must SUCCEED (201).
    //      This is the existing happy path — must stay green.
    // =========================================================================

    public function test_paying_posted_supplier_invoice_with_journal_entry_succeeds(): void
    {
        // Seed a Posted supplier_invoice with a real Cr-401 JE.
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Posted,
            'document_number' => 'SI-B1-OK-'.Str::upper(Str::random(4)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '300.000',
            'tax_amount' => '0.000',
            'total' => '300.000',
            'balance_due' => '300.000',
        ]);

        // Post a Cr-401 JE linked to this invoice (mimics SupplierInvoicePostingService).
        $expenseAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchaseExpenses);
        DB::transaction(function () use ($invoice, $expenseAccount): void {
            app(GeneralLedgerService::class)->createSupplierInvoiceJournalEntry(
                companyId: $this->company->id,
                partnerId: $this->supplier->id,
                invoiceId: $invoice->id,
                totalAmount: '300.000',
                netAmount: '300.000',
                vatAmount: '0.000',
                expenseAccountId: $expenseAccount->id,
                date: new \DateTimeImmutable('now'),
                user: $this->user,
                description: 'B1 test posting',
                currencyCode: 'TND',
            );
        });

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '300.00',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '300.00'],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payments', ['partner_id' => $this->supplier->id]);
    }

    // =========================================================================
    // B1b: after SupplierInvoicePostingService::post(), balance_due == total.
    //      Deferred B2 (credit-note decrement) will rely on this as the ceiling.
    // =========================================================================

    public function test_balance_due_set_to_total_on_posting(): void
    {
        // Full setup required by SupplierInvoicePostingService.
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
        ]);

        $grirAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-B1b-'.Str::upper(Str::random(4)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        /** @var DocumentLine $poLine */
        $poLine = DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'B1b PO line',
            'quantity' => '10.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
            'landed_unit_cost' => null,
            'line_total' => '100.000',
            'allocated_costs' => '0.0000',
        ]);

        // Accrue 408 (GR-IR Cr side).
        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            '10.0000',
            '10.000',
            'TND',
        );

        // Supplier invoice (Draft, balance_due NOT set).
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-B1b-'.Str::upper(Str::random(4)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            // balance_due intentionally NOT set
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'B1b SI line',
            'quantity' => '10.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '100.000',
            'allocated_costs' => '0.0000',
            'recoverable_tax_amount' => '0.000',
            'non_recoverable_tax_amount' => '0.000',
            'source_line_id' => $poLine->id,
        ]);

        $invoice->load('lines');

        // Pre-condition: balance_due is null.
        $this->assertNull($invoice->balance_due);

        app(SupplierInvoicePostingService::class)->post($invoice);

        $fresh = Document::findOrFail($invoice->id);
        // B1b: balance_due must be set to total at post time.
        $this->assertNotNull($fresh->balance_due, 'balance_due must be set on posting');
        $this->assertSame(0, bccomp((string) $fresh->balance_due, (string) $fresh->total, 3),
            "balance_due ({$fresh->balance_due}) must equal total ({$fresh->total}) after posting");
    }
}
