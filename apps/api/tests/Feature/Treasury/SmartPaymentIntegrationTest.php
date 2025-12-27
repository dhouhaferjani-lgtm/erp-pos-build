<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
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
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SmartPaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private PaymentRepository $cashRegister;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Create country
        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'domain' => 'test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
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

        // Setup permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create user
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

        // Create payment method
        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => false,
            'is_active' => true,
        ]);

        // Create payment repository
        $this->cashRegister = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_REG_01',
            'name' => 'Cash Register',
            'type' => 'cash_register',
            'is_active' => true,
        ]);

        // Create customer
        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        // Create chart of accounts with system purposes
        $this->createChartOfAccounts();
    }

    /** @test */
    public function it_gets_tolerance_settings_for_company(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/smart-payment/tolerance-settings');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'enabled',
                    'percentage',
                    'max_amount',
                    'source',
                ],
            ]);

        // System default settings (from PaymentToleranceService)
        $this->assertTrue($response->json('data.enabled'));
        $this->assertEquals('0.0050', $response->json('data.percentage'));
        $this->assertEquals('0.5000', $response->json('data.max_amount'));
    }

    /** @test */
    public function it_previews_fifo_allocation_for_multiple_invoices(): void
    {
        // Create three invoices
        $invoice1 = $this->createInvoice('INV-001', '1000.0000', now()->subDays(10));
        $invoice2 = $this->createInvoice('INV-002', '500.0000', now()->subDays(5));
        $invoice3 = $this->createInvoice('INV-003', '300.0000', now()->subDays(2));

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/smart-payment/preview-allocation', [
                'partner_id' => $this->customer->id,
                'payment_amount' => '1200.0000',
                'allocation_method' => 'fifo',
            ]);

        $response->assertOk();

        $allocations = $response->json('data.allocations');
        $this->assertCount(2, $allocations);

        // FIFO: oldest first
        $this->assertEquals($invoice1->id, $allocations[0]['document_id']);
        $this->assertEquals('1000.0000', $allocations[0]['amount']);

        $this->assertEquals($invoice2->id, $allocations[1]['document_id']);
        $this->assertEquals('200.0000', $allocations[1]['amount']);

        $this->assertEquals('1200.0000', $response->json('data.total_to_invoices'));
        $this->assertEquals('0.0000', $response->json('data.excess_amount'));
    }

    /** @test */
    public function it_previews_due_date_allocation_prioritizing_overdue(): void
    {
        // Create invoices with different due dates
        $invoice1 = $this->createInvoice('INV-001', '500.0000', now()->subDays(10), now()->subDays(5)); // Overdue 5 days
        $invoice2 = $this->createInvoice('INV-002', '300.0000', now()->subDays(8), now()->addDays(5)); // Not due yet
        $invoice3 = $this->createInvoice('INV-003', '400.0000', now()->subDays(15), now()->subDays(10)); // Overdue 10 days

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/smart-payment/preview-allocation', [
                'partner_id' => $this->customer->id,
                'payment_amount' => '600.0000',
                'allocation_method' => 'due_date',
            ]);

        $response->assertOk();

        $allocations = $response->json('data.allocations');
        $this->assertCount(2, $allocations);

        // Should prioritize invoice3 (most overdue), then invoice1
        $this->assertEquals($invoice3->id, $allocations[0]['document_id']);
        $this->assertEquals('400.0000', $allocations[0]['amount']);

        $this->assertEquals($invoice1->id, $allocations[1]['document_id']);
        $this->assertEquals('200.0000', $allocations[1]['amount']);
    }

    /** @test */
    public function it_previews_manual_allocation(): void
    {
        $invoice1 = $this->createInvoice('INV-001', '1000.0000', now()->subDays(10));
        $invoice2 = $this->createInvoice('INV-002', '500.0000', now()->subDays(5));

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/smart-payment/preview-allocation', [
                'partner_id' => $this->customer->id,
                'payment_amount' => '1200.0000',
                'allocation_method' => 'manual',
                'manual_allocations' => [
                    ['document_id' => $invoice2->id, 'amount' => '500.0000'],
                    ['document_id' => $invoice1->id, 'amount' => '700.0000'],
                ],
            ]);

        $response->assertOk();

        $allocations = $response->json('data.allocations');
        $this->assertCount(2, $allocations);

        $this->assertEquals($invoice2->id, $allocations[0]['document_id']);
        $this->assertEquals('500.0000', $allocations[0]['amount']);

        $this->assertEquals($invoice1->id, $allocations[1]['document_id']);
        $this->assertEquals('700.0000', $allocations[1]['amount']);
    }

    /** @test */
    public function it_handles_overpayment_within_tolerance(): void
    {
        $invoice = $this->createInvoice('INV-001', '100.0000', now()->subDays(5));

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/smart-payment/preview-allocation', [
                'partner_id' => $this->customer->id,
                'payment_amount' => '100.0500', // 0.05 TND overpayment (0.05%)
                'allocation_method' => 'fifo',
            ]);

        $response->assertOk();

        $allocations = $response->json('data.allocations');
        $this->assertCount(1, $allocations);
        $this->assertEquals('100.0000', $allocations[0]['amount']);
        $this->assertEquals('0.0500', $allocations[0]['tolerance_writeoff']);
        $this->assertEquals('tolerance_writeoff', $response->json('data.excess_handling'));
    }

    /** @test */
    public function it_handles_underpayment_within_tolerance(): void
    {
        $invoice = $this->createInvoice('INV-001', '100.0000', now()->subDays(5));

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/smart-payment/preview-allocation', [
                'partner_id' => $this->customer->id,
                'payment_amount' => '99.9500', // 0.05 TND underpayment (0.05%)
                'allocation_method' => 'fifo',
            ]);

        $response->assertOk();

        $allocations = $response->json('data.allocations');
        $this->assertCount(1, $allocations);
        $this->assertEquals('99.9500', $allocations[0]['amount']);
        $this->assertEquals('0.0500', $allocations[0]['tolerance_writeoff']);
        $this->assertEquals('tolerance_writeoff', $response->json('data.excess_handling'));
    }

    /** @test */
    public function it_applies_allocation_to_payment(): void
    {
        $invoice1 = $this->createInvoice('INV-001', '1000.0000', now()->subDays(10));
        $invoice2 = $this->createInvoice('INV-002', '500.0000', now()->subDays(5));

        // Create payment
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_repository_id' => $this->cashRegister->id,
            'amount' => '1200.0000',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/smart-payment/apply-allocation', [
                'payment_id' => $payment->id,
                'allocation_method' => 'fifo',
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Payment allocation applied successfully',
            ]);

        // Verify allocations were created
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'document_id' => $invoice1->id,
            'amount' => '1000.0000',
        ]);

        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'document_id' => $invoice2->id,
            'amount' => '200.0000',
        ]);

        // Note: balance_due update would be handled by a separate service or event listener
        // For now, we just verify allocations were created correctly
    }

    /** @test */
    public function it_handles_manual_allocation_with_excess(): void
    {
        $invoice = $this->createInvoice('INV-001', '1000.0000', now()->subDays(5));

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/smart-payment/preview-allocation', [
                'partner_id' => $this->customer->id,
                'payment_amount' => '1200.0000',
                'allocation_method' => 'manual',
                'manual_allocations' => [
                    ['document_id' => $invoice->id, 'amount' => '800.0000'], // Partial allocation
                ],
            ]);

        // Should succeed and show excess amount
        $response->assertOk();
        $this->assertEquals('800.0000', $response->json('data.total_to_invoices'));
        $this->assertEquals('400.0000', $response->json('data.excess_amount'));
    }

    /** @test */
    public function it_creates_customer_advance_gl_entry_for_excess_amount(): void
    {
        $invoice = $this->createInvoice('INV-001', '1000.0000', now()->subDays(10));

        // Create payment with excess (1500 against 1000 invoice)
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => '1500.0000',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
            'payment_type' => PaymentType::DocumentPayment,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/smart-payment/apply-allocation', [
                'payment_id' => $payment->id,
                'allocation_method' => 'fifo',
            ]);

        $response->assertOk();

        // Verify allocation was created for the invoice amount
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '1000.0000',
        ]);

        // Verify invoice balance was updated
        $invoice->refresh();
        $this->assertEquals('0.00', $invoice->balance_due);
        $this->assertEquals(DocumentStatus::Paid, $invoice->status);

        // Verify excess amount was returned
        $this->assertEquals('500.0000', $response->json('data.excess_amount'));

        // Verify customer advance GL entry was created
        $advanceEntry = JournalEntry::where('source_type', 'advance')
            ->where('source_id', $payment->id)
            ->first();

        $this->assertNotNull($advanceEntry, 'Customer advance GL entry should be created');
        $this->assertEquals($this->company->id, $advanceEntry->company_id);

        // Verify GL lines: Dr. Cash 500, Cr. Customer Advance 500
        $lines = $advanceEntry->lines;
        $this->assertCount(2, $lines);

        $debitLine = $lines->firstWhere('debit', '!=', '0.00');
        $creditLine = $lines->firstWhere('credit', '!=', '0.00');

        $this->assertEquals('500.00', $debitLine->debit);
        $this->assertEquals('500.00', $creditLine->credit);
        $this->assertEquals($this->customer->id, $creditLine->partner_id);
    }

    /** @test */
    public function it_creates_pure_advance_payment_when_no_open_invoices(): void
    {
        // No invoices created - customer pays in advance

        // Create payment without any invoices to allocate
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => '500.0000',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => 'completed',
            'payment_type' => PaymentType::DocumentPayment,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/smart-payment/apply-allocation', [
                'payment_id' => $payment->id,
                'allocation_method' => 'fifo',
            ]);

        $response->assertOk();

        // No allocations should be created
        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $payment->id,
        ]);

        // Entire amount should be excess (customer advance)
        // The amount may be formatted as '500.0000' or '500.00' depending on where in the flow
        $this->assertTrue(
            in_array($response->json('data.excess_amount'), ['500.0000', '500.00'], true),
            'Excess amount should be 500'
        );

        // Payment type should be updated to Advance
        $payment->refresh();
        $this->assertEquals(PaymentType::Advance, $payment->payment_type);

        // Verify customer advance GL entry was created
        $advanceEntry = JournalEntry::where('source_type', 'advance')
            ->where('source_id', $payment->id)
            ->first();

        $this->assertNotNull($advanceEntry, 'Customer advance GL entry should be created');
    }

    /** @test */
    public function it_returns_open_invoices_for_partner(): void
    {
        // Create invoices with different statuses
        $openInvoice1 = $this->createInvoice('INV-001', '1000.0000', now()->subDays(30), now()->subDays(15));
        $openInvoice2 = $this->createInvoice('INV-002', '500.0000', now()->subDays(20), now()->addDays(10));

        // Create a paid invoice (should not appear)
        $paidInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Paid,
            'document_number' => 'INV-003',
            'document_date' => now()->subDays(10),
            'currency' => 'TND',
            'subtotal' => '200.0000',
            'tax_amount' => '0.0000',
            'total' => '200.0000',
            'balance_due' => '0.00',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/partners/{$this->customer->id}/open-invoices");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        // Should be sorted by document_date (FIFO)
        $data = $response->json('data');
        $this->assertEquals('INV-001', $data[0]['document_number']);
        $this->assertEquals('INV-002', $data[1]['document_number']);

        // First invoice should show as overdue
        $this->assertGreaterThan(0, $data[0]['days_overdue']);

        // Second invoice should not be overdue yet
        $this->assertEquals(0, $data[1]['days_overdue']);
    }

    /** @test */
    public function it_returns_404_for_non_existent_partner(): void
    {
        $fakePartnerId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/partners/{$fakePartnerId}/open-invoices");

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'PARTNER_NOT_FOUND');
    }

    private function createInvoice(
        string $number,
        string $total,
        $documentDate,
        $dueDate = null
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => $number,
            'document_date' => $documentDate,
            'due_date' => $dueDate ?? now()->addDays(30),
            'currency' => 'TND',
            'subtotal' => $total,
            'tax_amount' => '0.0000',
            'total' => $total,
            'balance_due' => $total,
        ]);
    }

    private function createChartOfAccounts(): void
    {
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customers',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '701',
            'name' => 'Product Sales',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '658',
            'name' => 'Payment Tolerance Expense',
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::PaymentToleranceExpense,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '758',
            'name' => 'Payment Tolerance Income',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::PaymentToleranceIncome,
            'is_active' => true,
        ]);

        // Cash account for repository
        $cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '531',
            'name' => 'Cash',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);

        // Link cash register to cash account
        $this->cashRegister->account_id = $cashAccount->id;
        $this->cashRegister->save();

        // Customer advance account (liability - we owe customer until invoice issued)
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '419',
            'name' => 'Customer Advances',
            'type' => 'liability',
            'system_purpose' => SystemAccountPurpose::CustomerAdvance,
            'is_active' => true,
        ]);
    }
}
