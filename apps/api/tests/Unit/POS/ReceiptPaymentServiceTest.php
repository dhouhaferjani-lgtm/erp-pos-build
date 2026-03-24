<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptPaymentService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for ReceiptPaymentService.
 *
 * Tests split payment processing, Treasury integration, and GL entry creation.
 */
final class ReceiptPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptPaymentService $service;
    private CompanyContext $companyContext;
    private GeneralLedgerService $glService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyContext = $this->app->make(CompanyContext::class);
        $this->glService = $this->app->make(GeneralLedgerService::class);
        $this->service = new ReceiptPaymentService(
            $this->companyContext,
            $this->glService
        );
    }

    public function test_single_payment_method_success(): void
    {
        // Arrange
        $this->setupTestData();

        $receipt = $this->createReceipt([
            'total' => '100.00',
        ]);

        $repository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => $this->cashAccount->id,
        ]);

        $this->companyContext->setCompanyId($this->company->id);

        $payments = [
            [
                'payment_method_id' => $this->paymentMethod->id,
                'amount' => '100.00',
                'repository_id' => $repository->id,
            ],
        ];

        // Act
        $result = $this->service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: $payments,
            customerId: null
        );

        // Assert
        $this->assertArrayHasKey('receipt', $result);
        $this->assertArrayHasKey('receipt_payments', $result);
        $this->assertArrayHasKey('treasury_payments', $result);
        $this->assertArrayHasKey('change_due', $result);

        $this->assertCount(1, $result['receipt_payments']);
        $this->assertCount(1, $result['treasury_payments']);
        $this->assertEquals('0.000', $result['change_due']);

        // Verify database records
        $this->assertDatabaseHas('pos_receipt_payments', [
            'receipt_id' => $receipt->id,
            'amount' => '100.00',
        ]);

        $this->assertDatabaseHas('payments', [
            'amount' => '100.00',
            'payment_type' => 'pos',
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'pos_receipt',
            'source_id' => $receipt->id,
        ]);
    }

    public function test_split_payment_success(): void
    {
        // Arrange
        $this->setupTestData();

        $receipt = $this->createReceipt([
            'total' => '150.00',
        ]);

        $cashRepository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => $this->cashAccount->id,
        ]);

        $cardRepository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => $this->bankAccount->id,
        ]);

        $this->companyContext->setCompanyId($this->company->id);

        $payments = [
            [
                'payment_method_id' => $this->paymentMethod->id,
                'amount' => '75.00',
                'repository_id' => $cashRepository->id,
            ],
            [
                'payment_method_id' => $this->cardPaymentMethod->id,
                'amount' => '75.00',
                'repository_id' => $cardRepository->id,
                'card_last_four' => '1234',
            ],
        ];

        // Act
        $result = $this->service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: $payments,
            customerId: null
        );

        // Assert
        $this->assertCount(2, $result['receipt_payments']);
        $this->assertCount(2, $result['treasury_payments']);
        $this->assertEquals('0.000', $result['change_due']);

        // Verify both payments created
        $this->assertDatabaseCount('pos_receipt_payments', 2);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('journal_entries', 2); // One per payment
    }

    public function test_overpayment_returns_correct_change(): void
    {
        // Arrange
        $this->setupTestData();

        $receipt = $this->createReceipt([
            'total' => '95.50',
        ]);

        $repository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => $this->cashAccount->id,
        ]);

        $this->companyContext->setCompanyId($this->company->id);

        $payments = [
            [
                'payment_method_id' => $this->paymentMethod->id,
                'amount' => '100.00',
                'repository_id' => $repository->id,
            ],
        ];

        // Act
        $result = $this->service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: $payments,
            customerId: null
        );

        // Assert
        $this->assertEquals('4.500', $result['change_due']);
    }

    public function test_underpayment_throws_exception(): void
    {
        // Arrange
        $this->setupTestData();

        $receipt = $this->createReceipt([
            'total' => '100.00',
        ]);

        $repository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => $this->cashAccount->id,
        ]);

        $this->companyContext->setCompanyId($this->company->id);

        $payments = [
            [
                'payment_method_id' => $this->paymentMethod->id,
                'amount' => '50.00',
                'repository_id' => $repository->id,
            ],
        ];

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Total paid (50.000) is less than receipt total (100.000)');

        $this->service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: $payments,
            customerId: null
        );
    }

    public function test_receipt_already_paid_throws_exception(): void
    {
        // Arrange
        $this->setupTestData();

        $receipt = $this->createReceipt([
            'total' => '100.00',
        ]);

        // Create existing payment
        ReceiptPayment::factory()->create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        $repository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => $this->cashAccount->id,
        ]);

        $this->companyContext->setCompanyId($this->company->id);

        $payments = [
            [
                'payment_method_id' => $this->paymentMethod->id,
                'amount' => '100.00',
                'repository_id' => $repository->id,
            ],
        ];

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Receipt has already been paid');

        $this->service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: $payments,
            customerId: null
        );
    }

    public function test_repository_without_gl_account_throws_exception(): void
    {
        // Arrange
        $this->setupTestData();

        $receipt = $this->createReceipt([
            'total' => '100.00',
        ]);

        $repository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => null, // No GL account configured
        ]);

        $this->companyContext->setCompanyId($this->company->id);

        $payments = [
            [
                'payment_method_id' => $this->paymentMethod->id,
                'amount' => '100.00',
                'repository_id' => $repository->id,
            ],
        ];

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not have a GL account configured');

        $this->service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: $payments,
            customerId: null
        );
    }

    public function test_treasury_payment_linked_to_journal_entry(): void
    {
        // Arrange
        $this->setupTestData();

        $receipt = $this->createReceipt([
            'total' => '100.00',
        ]);

        $repository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => $this->cashAccount->id,
        ]);

        $this->companyContext->setCompanyId($this->company->id);

        $payments = [
            [
                'payment_method_id' => $this->paymentMethod->id,
                'amount' => '100.00',
                'repository_id' => $repository->id,
            ],
        ];

        // Act
        $result = $this->service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: $payments,
            customerId: null
        );

        // Assert
        $treasuryPayment = $result['treasury_payments'][0];
        $this->assertNotNull($treasuryPayment->journal_entry_id);

        // Verify journal entry exists and is posted
        $this->assertDatabaseHas('journal_entries', [
            'id' => $treasuryPayment->journal_entry_id,
            'status' => 'posted',
        ]);
    }

    public function test_receipt_payment_linked_to_treasury_payment(): void
    {
        // Arrange
        $this->setupTestData();

        $receipt = $this->createReceipt([
            'total' => '100.00',
        ]);

        $repository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => $this->cashAccount->id,
        ]);

        $this->companyContext->setCompanyId($this->company->id);

        $payments = [
            [
                'payment_method_id' => $this->paymentMethod->id,
                'amount' => '100.00',
                'repository_id' => $repository->id,
            ],
        ];

        // Act
        $result = $this->service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: $payments,
            customerId: null
        );

        // Assert
        $receiptPayment = $result['receipt_payments'][0];
        $treasuryPayment = $result['treasury_payments'][0];

        $this->assertEquals($treasuryPayment->id, $receiptPayment->treasury_payment_id);

        // Verify database linkage
        $this->assertDatabaseHas('pos_receipt_payments', [
            'id' => $receiptPayment->id,
            'treasury_payment_id' => $treasuryPayment->id,
        ]);
    }

    public function test_validate_payment_amounts_returns_true_for_valid_payments(): void
    {
        $payments = [
            ['amount' => '50.00'],
            ['amount' => '50.00'],
        ];

        $result = $this->service->validatePaymentAmounts($payments, '100.00');

        $this->assertTrue($result);
    }

    public function test_validate_payment_amounts_allows_overpayment(): void
    {
        $payments = [
            ['amount' => '100.00'],
        ];

        $result = $this->service->validatePaymentAmounts($payments, '95.00');

        $this->assertTrue($result);
    }

    public function test_validate_payment_amounts_returns_false_for_underpayment(): void
    {
        $payments = [
            ['amount' => '50.00'],
        ];

        $result = $this->service->validatePaymentAmounts($payments, '100.00');

        $this->assertFalse($result);
    }

    public function test_calculate_change_returns_correct_amount(): void
    {
        $change = $this->service->calculateChange('105.50', '100.00');

        $this->assertEquals('5.500', $change);
    }

    public function test_calculate_change_returns_zero_for_exact_payment(): void
    {
        $change = $this->service->calculateChange('100.00', '100.00');

        $this->assertEquals('0.000', $change);
    }

    // Helper method to set up test data
    private function setupTestData(): void
    {
        $this->tenant = \App\Modules\Tenant\Domain\Tenant::factory()->create();
        $this->company = \App\Modules\Company\Domain\Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->cashAccount = \App\Modules\Accounting\Domain\Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '531',
            'name' => 'Cash',
            'system_purpose' => 'cash',
        ]);

        $this->bankAccount = \App\Modules\Accounting\Domain\Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512',
            'name' => 'Bank',
            'system_purpose' => 'bank',
        ]);

        $this->revenueAccount = \App\Modules\Accounting\Domain\Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707',
            'name' => 'Sales Revenue',
            'system_purpose' => 'product_revenue',
        ]);

        $this->paymentMethod = \App\Modules\Treasury\Domain\PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash',
        ]);

        $this->cardPaymentMethod = \App\Modules\Treasury\Domain\PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Credit Card',
        ]);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    /**
     * Create a receipt with all required FK fields.
     *
     * @param array<string, mixed> $overrides
     */
    private function createReceipt(array $overrides = []): Receipt
    {
        return Receipt::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
        ], $overrides));
    }
}
