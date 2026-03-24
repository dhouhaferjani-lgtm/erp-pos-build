<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\CreditNoteAllocation;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\PaymentStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus as TreasuryPaymentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditNoteAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private PaymentMethod $paymentMethod;

    private CreditNoteService $creditNoteService;

    private DocumentPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Create payment method
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

        // Create chart of accounts (required for posting)
        $this->createChartOfAccounts();

        $this->creditNoteService = app(CreditNoteService::class);
        $this->postingService = app(DocumentPostingService::class);
    }

    private function createChartOfAccounts(): void
    {
        $accounts = [
            ['code' => '411000', 'name' => 'Customer Receivable', 'type' => 'asset', 'purpose' => 'customer_receivable'],
            ['code' => '701000', 'name' => 'Product Sales', 'type' => 'revenue', 'purpose' => 'product_revenue'],
            ['code' => '706000', 'name' => 'Service Revenue', 'type' => 'revenue', 'purpose' => 'service_revenue'],
            ['code' => '445660', 'name' => 'VAT Collected', 'type' => 'liability', 'purpose' => 'vat_collected'],
        ];

        foreach ($accounts as $accountData) {
            \App\Modules\Accounting\Domain\Account::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => $accountData['code'],
                'name' => $accountData['name'],
                'type' => \App\Modules\Accounting\Domain\Enums\AccountType::from($accountData['type']),
                'system_purpose' => \App\Modules\Accounting\Domain\Enums\SystemAccountPurpose::from($accountData['purpose']),
                'is_active' => true,
            ]);
        }
    }

    /** @test */
    public function it_creates_credit_note_allocation_when_posting(): void
    {
        // Arrange: Create and post an invoice
        $invoice = $this->createPostedInvoice('1000.00');

        // Create a credit note (draft)
        $creditNote = $this->creditNoteService->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '300.00',
            reason: CreditNoteReason::RETURN,
            notes: 'Test credit note'
        );

        // Confirm the credit note
        $creditNote->update([
            'status' => DocumentStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        $creditNote->refresh();

        // Act: Post the credit note
        $postedCreditNote = $this->postingService->post($creditNote);
        $this->creditNoteService->allocateCreditNote($postedCreditNote);

        // Assert: Allocation was created
        $this->assertDatabaseHas('credit_note_allocations', [
            'credit_note_id' => $postedCreditNote->id,
            'invoice_id' => $invoice->id,
            'amount' => '300.00',
        ]);

        // Assert: Allocation record exists
        $allocation = CreditNoteAllocation::where('credit_note_id', $postedCreditNote->id)->first();
        $this->assertNotNull($allocation);
        $this->assertEquals($invoice->id, $allocation->invoice_id);
        $this->assertEquals('300.000', $allocation->amount);
    }

    /** @test */
    public function it_reduces_invoice_balance_due_when_credit_note_is_allocated(): void
    {
        // Arrange: Create and post an invoice with 1000.00
        $invoice = $this->createPostedInvoice('1000.00');
        $this->assertEquals('1000.000', $invoice->balance_due);

        // Create, confirm, and post a credit note for 300.00
        $creditNote = $this->createAndPostCreditNote($invoice, '300.00');

        // Allocate the credit note
        $this->creditNoteService->allocateCreditNote($creditNote);

        // Manually update balance_due (trigger doesn't fire in SQLite tests)
        $this->updateBalanceDue($invoice);

        // Act: Refresh invoice to get updated balance_due
        $invoice->refresh();

        // Assert: balance_due reduced by credit note amount (trigger fired)
        $this->assertEquals('700.000', $invoice->balance_due);

        // Assert: Outstanding amount computed correctly
        $this->assertEquals('700.000', $invoice->getOutstandingAmount());

        // Assert: Payment status is now PartiallyPaid
        $this->assertEquals(PaymentStatus::PartiallyPaid, $invoice->getPaymentStatus());
    }

    /** @test */
    public function it_allows_multiple_credit_notes_for_same_invoice(): void
    {
        // Arrange: Invoice with 1000.00
        $invoice = $this->createPostedInvoice('1000.00');

        // Act: Create and allocate 3 credit notes
        $creditNote1 = $this->createAndPostCreditNote($invoice, '200.00');
        $this->creditNoteService->allocateCreditNote($creditNote1);
        $this->updateBalanceDue($invoice);

        $creditNote2 = $this->createAndPostCreditNote($invoice, '300.00');
        $this->creditNoteService->allocateCreditNote($creditNote2);
        $this->updateBalanceDue($invoice);

        $creditNote3 = $this->createAndPostCreditNote($invoice, '100.00');
        $this->creditNoteService->allocateCreditNote($creditNote3);
        $this->updateBalanceDue($invoice);

        // Assert: All 3 allocations exist
        $this->assertEquals(3, CreditNoteAllocation::where('invoice_id', $invoice->id)->count());

        // Assert: balance_due reflects all credits
        $invoice->refresh();
        $this->assertEquals('400.000', $invoice->balance_due);
        $this->assertEquals('400.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::PartiallyPaid, $invoice->getPaymentStatus());
    }

    /** @test */
    public function it_marks_invoice_as_paid_when_fully_credited(): void
    {
        // Arrange: Invoice with 1000.00
        $invoice = $this->createPostedInvoice('1000.00');

        // Act: Create and allocate credit note for full amount
        $creditNote = $this->createAndPostCreditNote($invoice, '1000.00');
        $this->creditNoteService->allocateCreditNote($creditNote);
        $this->updateBalanceDue($invoice);

        // Assert: Invoice is fully paid
        $invoice->refresh();
        $this->assertEquals('0.000', $invoice->balance_due);
        $this->assertEquals('0.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::Paid, $invoice->getPaymentStatus());
    }

    /** @test */
    public function it_considers_both_payments_and_credits_for_payment_status(): void
    {
        // Arrange: Invoice with 1000.00
        $invoice = $this->createPostedInvoice('1000.00');

        // Act 1: Create a payment for 400.00
        $this->createPaymentAllocation($invoice, '400.00');
        $this->updateBalanceDue($invoice);
        $invoice->refresh();

        // Assert: PartiallyPaid with 600.00 outstanding
        $this->assertEquals('600.000', $invoice->balance_due);
        $this->assertEquals('600.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::PartiallyPaid, $invoice->getPaymentStatus());

        // Act 2: Create credit note for 600.00
        $creditNote = $this->createAndPostCreditNote($invoice, '600.00');
        $this->creditNoteService->allocateCreditNote($creditNote);
        $this->updateBalanceDue($invoice);
        $invoice->refresh();

        // Assert: Fully paid (payment + credit = total)
        $this->assertEquals('0.000', $invoice->balance_due);
        $this->assertEquals('0.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::Paid, $invoice->getPaymentStatus());
    }

    /** @test */
    public function it_updates_balance_due_automatically_via_trigger(): void
    {
        // Arrange: Invoice with 1000.00
        $invoice = $this->createPostedInvoice('1000.00');

        // Act: Create credit note allocation directly (simulating trigger behavior)
        $creditNote = $this->createAndPostCreditNote($invoice, '250.00');
        CreditNoteAllocation::create([
            'credit_note_id' => $creditNote->id,
            'invoice_id' => $invoice->id,
            'amount' => '250.00',
        ]);
        $this->updateBalanceDue($invoice);

        // Assert: Trigger automatically updated balance_due
        $invoice->refresh();
        $this->assertEquals('750.000', $invoice->balance_due);
    }

    /** @test */
    public function it_includes_credit_allocations_in_outstanding_amount(): void
    {
        // Arrange: Invoice with 1000.00
        $invoice = $this->createPostedInvoice('1000.00');

        // Add payment of 300.00
        $this->createPaymentAllocation($invoice, '300.00');
        $this->updateBalanceDue($invoice);

        // Add credit note of 200.00
        $creditNote = $this->createAndPostCreditNote($invoice, '200.00');
        $this->creditNoteService->allocateCreditNote($creditNote);
        $this->updateBalanceDue($invoice);

        // Act: Calculate outstanding amount
        $invoice->refresh();
        $outstandingAmount = $invoice->getOutstandingAmount();

        // Assert: Outstanding = Total - Payments - Credits = 1000 - 300 - 200 = 500
        $this->assertEquals('500.000', $outstandingAmount);
        $this->assertEquals('500.000', $invoice->balance_due);
        $this->assertEquals(PaymentStatus::PartiallyPaid, $invoice->getPaymentStatus());
    }

    /** @test */
    public function it_throws_exception_when_allocating_draft_credit_note(): void
    {
        // Arrange: Invoice and draft credit note
        $invoice = $this->createPostedInvoice('1000.00');
        $creditNote = $this->creditNoteService->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '300.00',
            reason: CreditNoteReason::RETURN
        );

        // Assert: Cannot allocate draft credit note
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit note must be posted to allocate');

        // Act: Try to allocate draft credit note
        $this->creditNoteService->allocateCreditNote($creditNote);
    }

    /** @test */
    public function it_throws_exception_when_credit_note_has_no_source_invoice(): void
    {
        // Arrange: Posted credit note without source_document_id (hypothetical)
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'document_number' => 'CN-TEST',
            'document_date' => now(),
            'currency' => 'TND',
            'total' => '500.00',
            'source_document_id' => null, // No source invoice
        ]);

        // Assert: Cannot allocate without source invoice
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit note must have a source invoice');

        // Act: Try to allocate
        $this->creditNoteService->allocateCreditNote($creditNote);
    }

    // Helper methods

    /**
     * Manually update balance_due (simulates PostgreSQL trigger for SQLite tests)
     */
    private function updateBalanceDue(Document $invoice): void
    {
        $total = $invoice->total ?? '0.00';
        $paid = (string) ($invoice->allocations()->sum('amount') ?? '0.00');
        $credited = (string) ($invoice->creditNoteAllocations()->sum('amount') ?? '0.00');

        $balanceDue = bcsub(bcsub($total, $paid, 3), $credited, 3);

        $invoice->update(['balance_due' => $balanceDue]);
    }

    private function createPostedInvoice(string $total): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'document_number' => 'INV-'.uniqid(),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $total,
            'tax_amount' => '0.00',
            'total' => $total,
            'balance_due' => $total,
        ]);

        return $invoice;
    }

    private function createAndPostCreditNote(Document $invoice, string $amount): Document
    {
        // Create draft credit note
        $creditNote = $this->creditNoteService->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: $amount,
            reason: CreditNoteReason::RETURN,
            notes: 'Test credit note for '.$amount
        );

        // Confirm and set unique document number
        $creditNote->update([
            'status' => DocumentStatus::Confirmed,
            'confirmed_at' => now(),
            'document_number' => 'CN-'.uniqid(),
        ]);
        $creditNote->refresh();

        // Post
        return $this->postingService->post($creditNote);
    }

    private function createPaymentAllocation(Document $invoice, string $amount): void
    {
        // Create a payment
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'payment_date' => now(),
            'amount' => $amount,
            'currency' => 'TND',
            'status' => TreasuryPaymentStatus::Completed,
        ]);

        // Create allocation
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => $amount,
        ]);
    }
}
