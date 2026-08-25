<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
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
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\MultiPaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression tests to ensure the canTransitionToPaid() guard is not removed.
 *
 * Sales Orders and Purchase Orders must NEVER transition to "paid" status,
 * because they need to retain "confirmed" for downstream conversion workflows.
 */
class DocumentPaymentStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private PaymentRepository $cashRegister;

    private Partner $customer;

    private Partner $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-status',
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
            'email' => 'user-status@example.com',
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

        // Full chart of accounts so admin payments can post a balanced GL entry
        // (AR/revenue/cash) once the repository is ledgered.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $cashGlAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);

        $this->cashRegister = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-01',
            'name' => 'Main Cash Register',
            'type' => RepositoryType::CashRegister,
            // Ledgered repository (gl_account_id set) — admin payments require a
            // ledger account to post the cash leg; an unledgered repository is
            // rejected with PAYMENT_REQUIRES_LEDGERED_REPOSITORY.
            'gl_account_id' => $cashGlAccount->id,
            'is_active' => true,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->vendor = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Vendor',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);
    }

    public function test_fully_paid_sales_order_retains_confirmed_status(): void
    {
        $so = $this->createDocument(DocumentType::SalesOrder, DocumentStatus::Confirmed, '1000.00', $this->customer);

        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => '1000.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $so->id, 'amount' => '1000.00'],
            ],
        ])->assertCreated();

        $so->refresh();
        $this->assertEquals(DocumentStatus::Confirmed, $so->status, 'Sales Order must stay Confirmed after full payment');
        $this->assertEquals('0.000', $so->balance_due, 'Balance should be zero');
    }

    /**
     * C-0a0 (F-153 / LEDGER OQ-3) — was
     * `test_fully_paid_purchase_order_retains_confirmed_status`, which asserted
     * that paying a purchase order SUCCEEDS.
     *
     * It does not any more, and the old assertion was pinning a defect: the
     * allocation booked `AllocationTreatment::ReceivableClearing` — a NEGATIVE
     * CUSTOMER receivable (Cr 411) against a SUPPLIER partner. N-6 kept that row
     * only because it was live and refusing it was out of that lane's scope
     * (its named residual R-1). SPEC §2.1 rule 9 refuses purchase orders
     * throughout this program; a genuine supplier prepayment belongs in a
     * supplier-advance account (Dr 409) and is a separate program.
     */
    public function test_paying_a_purchase_order_is_refused(): void
    {
        $po = $this->createDocument(DocumentType::PurchaseOrder, DocumentStatus::Confirmed, '1000.00', $this->vendor);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->vendor->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => '1000.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $po->id, 'amount' => '1000.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', 'purchase_order_wrong_direction');

        $po->refresh();
        $this->assertEquals(DocumentStatus::Confirmed, $po->status);
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $po->id)->count());
    }

    public function test_fully_paid_invoice_transitions_to_paid_status(): void
    {
        $invoice = $this->createDocument(DocumentType::Invoice, DocumentStatus::Posted, '1000.00', $this->customer);

        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => '1000.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '1000.00'],
            ],
        ])->assertCreated();

        $invoice->refresh();
        $this->assertEquals(DocumentStatus::Paid, $invoice->status, 'Invoice should transition to Paid');
        $this->assertEquals('0.000', $invoice->balance_due, 'Balance should be zero');
    }

    public function test_partial_payment_on_sales_order_keeps_confirmed_status(): void
    {
        $so = $this->createDocument(DocumentType::SalesOrder, DocumentStatus::Confirmed, '1000.00', $this->customer);

        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => '500.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $so->id, 'amount' => '500.00'],
            ],
        ])->assertCreated();

        $so->refresh();
        $this->assertEquals(DocumentStatus::Confirmed, $so->status, 'Sales Order must stay Confirmed after partial payment');
        $this->assertEquals('500.000', $so->balance_due, 'Balance should reflect partial payment');
    }

    public function test_split_payment_on_sales_order_retains_confirmed_status(): void
    {
        $so = $this->createDocument(DocumentType::SalesOrder, DocumentStatus::Confirmed, '1000.00', $this->customer);

        $cardMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Credit Card',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $bankAccount = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK-01',
            'name' => 'Bank Account',
            'type' => RepositoryType::BankAccount,
            'is_active' => true,
        ]);

        /** @var MultiPaymentService $multiPaymentService */
        $multiPaymentService = app(MultiPaymentService::class);

        $payments = $multiPaymentService->createSplitPayment(
            $so,
            [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'amount' => '600.00',
                    'repository_id' => $this->cashRegister->id,
                ],
                [
                    'payment_method_id' => $cardMethod->id,
                    'amount' => '400.00',
                    'repository_id' => $bankAccount->id,
                ],
            ],
            $this->user->id
        );

        $this->assertCount(2, $payments);

        $so->refresh();
        $this->assertEquals(DocumentStatus::Confirmed, $so->status, 'Sales Order must stay Confirmed after split payment');
        $this->assertEquals('0.000', $so->balance_due, 'Balance should be zero after full split payment');
    }

    public function test_multi_document_payment_only_transitions_invoice_to_paid(): void
    {
        // Create a confirmed SO and a posted Invoice for the same partner
        $so = $this->createDocument(DocumentType::SalesOrder, DocumentStatus::Confirmed, '500.00', $this->customer);
        $invoice = $this->createDocument(DocumentType::Invoice, DocumentStatus::Posted, '500.00', $this->customer);

        // Pay both documents in a single payment (SO allocation first, then invoice)
        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => '1000.00',
            'currency' => 'EUR',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $so->id, 'amount' => '500.00'],
                ['document_id' => $invoice->id, 'amount' => '500.00'],
            ],
        ])->assertCreated();

        $invoice->refresh();
        $so->refresh();

        $this->assertEquals('0.000', $invoice->balance_due, 'Invoice balance should be zero');
        $this->assertEquals(DocumentStatus::Paid, $invoice->status, 'Invoice should transition to Paid');
        $this->assertEquals('0.000', $so->balance_due, 'SO balance should be zero');
        $this->assertEquals(DocumentStatus::Confirmed, $so->status, 'Sales Order must stay Confirmed even when fully paid');
    }

    private function createDocument(DocumentType $type, DocumentStatus $status, string $total, Partner $partner): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => $type,
            'document_number' => $type->getPrefix().'-'.now()->format('Y').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_date' => now(),
            'status' => $status,
            'confirmed_at' => $status === DocumentStatus::Confirmed ? now() : null,
            'subtotal' => bcmul($total, '0.833333', 2),
            'tax_amount' => bcmul($total, '0.166667', 2),
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'EUR',
        ]);
    }
}
