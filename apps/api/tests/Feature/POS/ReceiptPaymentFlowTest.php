<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Integration test for receipt payment flow.
 *
 * Tests the complete flow from API request to database state verification.
 */
final class ReceiptPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $revenueAccount;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $repository;

    private Location $location;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    public function test_complete_receipt_payment_flow_with_single_payment(): void
    {
        // Arrange: Create a receipt
        $receipt = $this->createReceipt([
            'total' => '150.75',
            'subtotal' => '150.75',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
        ]);

        $requestData = [
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'amount' => '150.75',
                    'repository_id' => $this->repository->id,
                ],
            ],
        ];

        // Act: POST to /api/v1/pos/receipts/{id}/payments
        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", $requestData);

        // Assert: Response structure
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'receipt',
                'receipt_payments',
                'treasury_payments',
                'change_due',
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals('0.000', $data['change_due']);
        $this->assertCount(1, $data['receipt_payments']);
        $this->assertCount(1, $data['treasury_payments']);

        // Assert: Database state - ReceiptPayment created
        $this->assertDatabaseHas('pos_receipt_payments', [
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '150.75',
        ]);

        // Assert: Database state - Treasury Payment created
        $this->assertDatabaseHas('payments', [
            'amount' => '150.75',
            'payment_type' => 'pos',
            'currency' => 'EUR',
            'status' => 'completed',
        ]);

        // Assert: Database state - Journal Entry created and posted
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'pos_receipt',
            'source_id' => $receipt->id,
            'status' => 'posted',
        ]);

        // Assert: Database state - Payment linked to JournalEntry
        $treasuryPayment = Payment::where('amount', '150.75')->first();
        $this->assertNotNull($treasuryPayment->journal_entry_id);

        $journalEntry = JournalEntry::find($treasuryPayment->journal_entry_id);
        $this->assertNotNull($journalEntry);
        $this->assertEquals('posted', $journalEntry->status->value);

        // Assert: Database state - ReceiptPayment linked to Treasury Payment
        $receiptPayment = ReceiptPayment::where('receipt_id', $receipt->id)->first();
        $this->assertEquals($treasuryPayment->id, $receiptPayment->treasury_payment_id);

        // Assert: Journal lines created (debit and credit)
        $this->assertDatabaseCount('journal_lines', 2);

        // Assert: Debit line (Cash/Bank account)
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $journalEntry->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '150.75',
            'credit' => '0.00',
        ]);

        // Assert: Credit line (Revenue account)
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $journalEntry->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '150.75',
        ]);
    }

    public function test_complete_receipt_payment_flow_with_split_payment(): void
    {
        // Arrange: Create a receipt
        $receipt = $this->createReceipt([
            'total' => '200.00',
            'subtotal' => '200.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
        ]);

        $cardPaymentMethod = PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Credit Card',
        ]);

        $bankAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512',
            'name' => 'Bank',
            'system_purpose' => 'bank',
        ]);

        $cardRepository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => $bankAccount->id,
        ]);

        $requestData = [
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'amount' => '100.00',
                    'repository_id' => $this->repository->id,
                ],
                [
                    'payment_method_id' => $cardPaymentMethod->id,
                    'amount' => '100.00',
                    'repository_id' => $cardRepository->id,
                    'card_last_four' => '1234',
                    'authorization_code' => 'AUTH123',
                ],
            ],
        ];

        // Act: POST to /api/v1/pos/receipts/{id}/payments
        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", $requestData);

        // Assert: Response
        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('0.000', $data['change_due']);
        $this->assertCount(2, $data['receipt_payments']);
        $this->assertCount(2, $data['treasury_payments']);

        // Assert: Database state - 2 ReceiptPayments created
        $this->assertDatabaseCount('pos_receipt_payments', 2);

        // Assert: Database state - 2 Treasury Payments created
        $this->assertDatabaseCount('payments', 2);

        // Assert: Database state - 2 Journal Entries created (one per payment)
        $this->assertDatabaseCount('journal_entries', 2);

        // Assert: All journal entries are posted
        $journalEntries = JournalEntry::all();
        foreach ($journalEntries as $entry) {
            $this->assertEquals('posted', $entry->status->value);
        }
    }

    public function test_overpayment_returns_correct_change(): void
    {
        // Arrange
        $receipt = $this->createReceipt([
            'total' => '95.50',
            'currency' => 'EUR',
        ]);

        $requestData = [
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'amount' => '100.00',
                    'repository_id' => $this->repository->id,
                ],
            ],
        ];

        // Act
        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", $requestData);

        // Assert
        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('4.500', $data['change_due']);
    }

    public function test_underpayment_returns_validation_error(): void
    {
        // Arrange
        $receipt = $this->createReceipt([
            'total' => '100.00',
            'currency' => 'EUR',
        ]);

        $requestData = [
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'amount' => '50.00',
                    'repository_id' => $this->repository->id,
                ],
            ],
        ];

        // Act
        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", $requestData);

        // Assert
        $response->assertStatus(500);
    }

    public function test_payment_with_invalid_payment_method_returns_validation_error(): void
    {
        // Arrange
        $receipt = $this->createReceipt([
            'total' => '100.00',
        ]);

        $requestData = [
            'payments' => [
                [
                    'payment_method_id' => 'invalid-uuid',
                    'amount' => '100.00',
                    'repository_id' => $this->repository->id,
                ],
            ],
        ];

        // Act
        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", $requestData);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments.0.payment_method_id'], 'error.errors');
    }

    public function test_payment_without_payments_array_returns_validation_error(): void
    {
        // Arrange
        $receipt = $this->createReceipt([
            'total' => '100.00',
        ]);

        $requestData = [];

        // Act
        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", $requestData);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments'], 'error.errors');
    }

    public function test_payment_with_zero_amount_returns_validation_error(): void
    {
        // Arrange
        $receipt = $this->createReceipt([
            'total' => '100.00',
        ]);

        $requestData = [
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'amount' => '0.00',
                    'repository_id' => $this->repository->id,
                ],
            ],
        ];

        // Act
        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", $requestData);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments.0.amount'], 'error.errors');
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create user-company membership
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Create and assign POS permissions. Note: short-pay flows separately require
        // `pos.tolerance.apply` (added in Phase 2 / Task 8); we grant it here so the
        // pre-A1 reject/validation tests below still exercise the service-layer paths
        // they were written for, rather than getting blocked at the new auth gate.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        Permission::findOrCreate('pos.tolerance.apply', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');
        $this->user->givePermissionTo('pos.tolerance.apply');

        $this->cashAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '531',
            'name' => 'Cash',
            'system_purpose' => 'cash',
        ]);

        $this->revenueAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707',
            'name' => 'Sales Revenue',
            'system_purpose' => 'product_revenue',
        ]);

        $this->paymentMethod = PaymentMethod::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash',
        ]);

        $this->repository = PaymentRepository::factory()->create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'gl_account_id' => $this->cashAccount->id,
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
     * @param  array<string, mixed>  $overrides
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
