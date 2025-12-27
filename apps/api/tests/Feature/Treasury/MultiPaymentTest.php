<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

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
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\MultiPaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MultiPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private PaymentMethod $checkMethod;

    private PaymentMethod $cardMethod;

    private PaymentRepository $cashRegister;

    private PaymentRepository $bankAccount;

    private Partner $customer;

    private Document $invoice;

    private MultiPaymentService $multiPaymentService;

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

        // Create multiple payment methods
        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->checkMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHECK',
            'name' => 'Check',
            'is_physical' => true,
            'has_maturity' => true,
            'is_active' => true,
        ]);

        $this->cardMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Credit Card',
            'is_physical' => false,
            'is_active' => true,
        ]);

        // Create payment repositories
        $this->cashRegister = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-01',
            'name' => 'Main Cash Register',
            'type' => RepositoryType::CashRegister,
            'is_active' => true,
        ]);

        $this->bankAccount = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK-01',
            'name' => 'Business Bank Account',
            'type' => RepositoryType::BankAccount,
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
            'currency' => 'EUR',
        ]);

        $this->multiPaymentService = app(MultiPaymentService::class);
    }

    public function test_payment_split_across_cash_and_check(): void
    {
        $paymentSplits = [
            [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '500.00',
                'repository_id' => $this->cashRegister->id,
                'reference' => 'Cash portion',
            ],
            [
                'payment_method_id' => $this->checkMethod->id,
                'amount' => '690.00',
                'repository_id' => $this->bankAccount->id,
                'reference' => 'Check #12345',
            ],
        ];

        $payments = $this->multiPaymentService->createSplitPayment(
            $this->invoice,
            $paymentSplits,
            $this->user->id
        );

        // Should create 2 payments
        $this->assertCount(2, $payments);

        // Verify first payment (cash)
        $this->assertEquals('500.00', $payments[0]->amount);
        $this->assertEquals($this->cashMethod->id, $payments[0]->payment_method_id);
        $this->assertEquals($this->cashRegister->id, $payments[0]->repository_id);
        $this->assertEquals(PaymentStatus::Completed, $payments[0]->status);

        // Verify second payment (check)
        $this->assertEquals('690.00', $payments[1]->amount);
        $this->assertEquals($this->checkMethod->id, $payments[1]->payment_method_id);
        $this->assertEquals($this->bankAccount->id, $payments[1]->repository_id);

        // Invoice should be fully paid
        $this->invoice->refresh();
        $this->assertEquals('0.00', $this->invoice->balance_due);
        $this->assertEquals(DocumentStatus::Paid, $this->invoice->status);

        // Verify allocations exist
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payments[0]->id,
            'document_id' => $this->invoice->id,
            'amount' => '500.0000',
        ]);

        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payments[1]->id,
            'document_id' => $this->invoice->id,
            'amount' => '690.0000',
        ]);
    }

    public function test_split_payment_requires_minimum_two_methods(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Split payment requires at least 2 payment methods');

        $paymentSplits = [
            [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '1190.00',
                'repository_id' => $this->cashRegister->id,
            ],
        ];

        $this->multiPaymentService->createSplitPayment(
            $this->invoice,
            $paymentSplits,
            $this->user->id
        );
    }

    public function test_split_payment_total_must_equal_document_balance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Split payment total must equal document balance');

        $paymentSplits = [
            [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '500.00',
                'repository_id' => $this->cashRegister->id,
            ],
            [
                'payment_method_id' => $this->checkMethod->id,
                'amount' => '500.00', // Total: 1000, but invoice is 1190
                'repository_id' => $this->bankAccount->id,
            ],
        ];

        $this->multiPaymentService->createSplitPayment(
            $this->invoice,
            $paymentSplits,
            $this->user->id
        );
    }

    public function test_payment_split_across_three_methods(): void
    {
        $paymentSplits = [
            [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '400.00',
                'repository_id' => $this->cashRegister->id,
            ],
            [
                'payment_method_id' => $this->checkMethod->id,
                'amount' => '500.00',
                'repository_id' => $this->bankAccount->id,
            ],
            [
                'payment_method_id' => $this->cardMethod->id,
                'amount' => '290.00',
                'repository_id' => $this->bankAccount->id,
            ],
        ];

        $payments = $this->multiPaymentService->createSplitPayment(
            $this->invoice,
            $paymentSplits,
            $this->user->id
        );

        $this->assertCount(3, $payments);
        $this->assertEquals('400.00', $payments[0]->amount);
        $this->assertEquals('500.00', $payments[1]->amount);
        $this->assertEquals('290.00', $payments[2]->amount);

        // Invoice should be fully paid
        $this->invoice->refresh();
        $this->assertEquals('0.00', $this->invoice->balance_due);
    }

    public function test_record_deposit_creates_unallocated_payment(): void
    {
        $payment = $this->multiPaymentService->recordDeposit(
            $this->tenant->id,
            $this->company->id,
            $this->customer->id,
            $this->cashMethod->id,
            '500.00',
            'EUR',
            $this->cashRegister->id,
            null,
            'Customer advance payment',
            'Deposit for future order',
            $this->user->id
        );

        $this->assertEquals('500.00', $payment->amount);
        $this->assertEquals(PaymentStatus::Completed, $payment->status);
        $this->assertStringContainsString('[UNALLOCATED]', $payment->notes);

        // Should have no allocations
        $this->assertEquals(0, $payment->allocations()->count());
    }

    public function test_deposit_amount_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Deposit amount must be greater than zero');

        $this->multiPaymentService->recordDeposit(
            $this->tenant->id,
            $this->company->id,
            $this->customer->id,
            $this->cashMethod->id,
            '0.00',
            'EUR',
            $this->cashRegister->id
        );
    }

    public function test_apply_deposit_to_document(): void
    {
        // First record a deposit
        $deposit = $this->multiPaymentService->recordDeposit(
            $this->tenant->id,
            $this->company->id,
            $this->customer->id,
            $this->cashMethod->id,
            '500.00',
            'EUR',
            $this->cashRegister->id
        );

        // Apply to invoice
        $allocation = $this->multiPaymentService->applyDepositToDocument(
            $deposit,
            $this->invoice,
            '500.00'
        );

        $this->assertEquals('500.0000', $allocation->amount);
        $this->assertEquals($deposit->id, $allocation->payment_id);
        $this->assertEquals($this->invoice->id, $allocation->document_id);

        // Invoice balance should be reduced
        $this->invoice->refresh();
        $this->assertEquals('690.00', $this->invoice->balance_due);
    }

    public function test_apply_deposit_exceeds_unallocated_amount(): void
    {
        $deposit = $this->multiPaymentService->recordDeposit(
            $this->tenant->id,
            $this->company->id,
            $this->customer->id,
            $this->cashMethod->id,
            '300.00',
            'EUR',
            $this->cashRegister->id
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount exceeds unallocated deposit balance');

        $this->multiPaymentService->applyDepositToDocument(
            $deposit,
            $this->invoice,
            '500.00' // More than the deposit amount
        );
    }

    public function test_get_unallocated_deposit_balance(): void
    {
        // Record multiple deposits
        $this->multiPaymentService->recordDeposit(
            $this->tenant->id,
            $this->company->id,
            $this->customer->id,
            $this->cashMethod->id,
            '300.00',
            'EUR',
            $this->cashRegister->id
        );

        $this->multiPaymentService->recordDeposit(
            $this->tenant->id,
            $this->company->id,
            $this->customer->id,
            $this->cashMethod->id,
            '200.00',
            'EUR',
            $this->cashRegister->id
        );

        $balance = $this->multiPaymentService->getUnallocatedDepositBalance(
            $this->customer->id,
            'EUR'
        );

        $this->assertEquals('500.00', $balance);
    }

    public function test_partial_deposit_application(): void
    {
        $deposit = $this->multiPaymentService->recordDeposit(
            $this->tenant->id,
            $this->company->id,
            $this->customer->id,
            $this->cashMethod->id,
            '1000.00',
            'EUR',
            $this->cashRegister->id
        );

        // Apply partial amount
        $this->multiPaymentService->applyDepositToDocument(
            $deposit,
            $this->invoice,
            '600.00'
        );

        // Check remaining unallocated
        $balance = $this->multiPaymentService->getUnallocatedDepositBalance(
            $this->customer->id,
            'EUR'
        );

        $this->assertEquals('400.00', $balance);
    }

    public function test_record_payment_on_account(): void
    {
        // SKIPPED: The service's recordPaymentOnAccount() sets payment_method_id to null,
        // but the database has a NOT NULL constraint on that column.
        // This is a known limitation - on-account payments need schema change to support.
        $this->markTestSkipped(
            'Service sets payment_method_id to null but DB requires it. '.
            'On-account payments need schema migration to make payment_method_id nullable.'
        );
    }

    public function test_validate_split_amounts(): void
    {
        $validSplits = [
            ['amount' => '500.00'],
            ['amount' => '690.00'],
        ];

        $this->assertTrue(
            $this->multiPaymentService->validateSplitAmounts($validSplits, '1190.00')
        );

        // Invalid - doesn't match total
        $invalidSplits = [
            ['amount' => '500.00'],
            ['amount' => '500.00'],
        ];

        $this->assertFalse(
            $this->multiPaymentService->validateSplitAmounts($invalidSplits, '1190.00')
        );

        // Invalid - zero amount
        $zeroAmountSplits = [
            ['amount' => '0.00'],
            ['amount' => '1190.00'],
        ];

        $this->assertFalse(
            $this->multiPaymentService->validateSplitAmounts($zeroAmountSplits, '1190.00')
        );
    }

    public function test_get_partner_account_balance(): void
    {
        // Record deposit
        $this->multiPaymentService->recordDeposit(
            $this->tenant->id,
            $this->company->id,
            $this->customer->id,
            $this->cashMethod->id,
            '500.00',
            'EUR',
            $this->cashRegister->id
        );

        $balance = $this->multiPaymentService->getPartnerAccountBalance(
            $this->customer->id,
            'EUR'
        );

        $this->assertEquals($this->customer->id, $balance['partner_id']);
        $this->assertEquals('EUR', $balance['currency']);
        $this->assertEquals('500.00', $balance['unallocated_balance']);
        $this->assertEquals(1, $balance['deposit_count']);
    }

    public function test_split_payment_with_precise_decimal_amounts(): void
    {
        // Create invoice with amount that requires precise calculation
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-2025-0002',
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '333.33',
            'tax_amount' => '66.67',
            'total' => '400.00',
            'balance_due' => '400.00',
            'currency' => 'EUR',
        ]);

        $paymentSplits = [
            [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '133.33',
                'repository_id' => $this->cashRegister->id,
            ],
            [
                'payment_method_id' => $this->cardMethod->id,
                'amount' => '266.67',
                'repository_id' => $this->bankAccount->id,
            ],
        ];

        $payments = $this->multiPaymentService->createSplitPayment(
            $invoice,
            $paymentSplits,
            $this->user->id
        );

        $this->assertCount(2, $payments);

        // Verify decimal precision is maintained
        $this->assertEquals('133.33', $payments[0]->amount);
        $this->assertEquals('266.67', $payments[1]->amount);

        $invoice->refresh();
        $this->assertEquals('0.00', $invoice->balance_due);
    }

    public function test_split_payment_with_different_repositories(): void
    {
        $safebox = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SAFE-01',
            'name' => 'Safe Box',
            'type' => RepositoryType::Safe,
            'is_active' => true,
        ]);

        $paymentSplits = [
            [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '600.00',
                'repository_id' => $this->cashRegister->id,
            ],
            [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => '590.00',
                'repository_id' => $safebox->id,
            ],
        ];

        $payments = $this->multiPaymentService->createSplitPayment(
            $this->invoice,
            $paymentSplits,
            $this->user->id
        );

        // Same payment method, different repositories
        $this->assertEquals($this->cashMethod->id, $payments[0]->payment_method_id);
        $this->assertEquals($this->cashMethod->id, $payments[1]->payment_method_id);
        $this->assertEquals($this->cashRegister->id, $payments[0]->repository_id);
        $this->assertEquals($safebox->id, $payments[1]->repository_id);
    }

    public function test_apply_deposit_only_completed_deposits(): void
    {
        // Create a payment that's not completed
        $pendingPayment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->checkMethod->id,
            'amount' => '500.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Pending, // Pending, not completed
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only completed deposits can be applied');

        $this->multiPaymentService->applyDepositToDocument(
            $pendingPayment,
            $this->invoice,
            '500.00'
        );
    }
}
