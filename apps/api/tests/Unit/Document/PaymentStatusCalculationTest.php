<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentStatusCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_invoice_has_correct_status(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $this->assertEquals('100.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::Unpaid, $invoice->getPaymentStatus());
    }

    public function test_partially_paid_invoice(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $payment = Payment::factory()->create();

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '60.00',
        ]);

        // Refresh to get updated allocations
        $invoice = $this->freshDocument($invoice);

        $this->assertEquals('40.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::PartiallyPaid, $invoice->getPaymentStatus());
    }

    public function test_paid_invoice(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $payment = Payment::factory()->create();

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '100.00',
        ]);

        $invoice = $this->freshDocument($invoice);

        $this->assertEquals('0.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::Paid, $invoice->getPaymentStatus());
    }

    public function test_document_data_uses_allocation_outstanding_when_balance_due_cache_is_null(): void
    {
        $fullyPaidInvoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.000',
            'balance_due' => null,
        ]);

        PaymentAllocation::create([
            'payment_id' => Payment::factory()->create()->id,
            'document_id' => $fullyPaidInvoice->id,
            'amount' => '100.000',
        ]);

        $fullyPaidListData = DocumentData::fromModel($this->freshDocument($fullyPaidInvoice), false);
        $fullyPaidDetailData = DocumentData::fromModel($this->freshDocument($fullyPaidInvoice), true);

        $this->assertSame($fullyPaidDetailData->payment_status, $fullyPaidListData->payment_status);
        $this->assertSame('paid', $fullyPaidListData->payment_status);
        $this->assertSame('0.000', $fullyPaidListData->balance_due);
        $this->assertSame('100.000', $fullyPaidListData->amount_paid);
        $this->assertSame($fullyPaidDetailData->balance_due, $fullyPaidListData->balance_due);
        $this->assertSame($fullyPaidDetailData->amount_paid, $fullyPaidListData->amount_paid);

        $partiallyPaidInvoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.000',
            'balance_due' => null,
        ]);

        PaymentAllocation::create([
            'payment_id' => Payment::factory()->create()->id,
            'document_id' => $partiallyPaidInvoice->id,
            'amount' => '40.000',
        ]);

        $partialListData = DocumentData::fromModel($this->freshDocument($partiallyPaidInvoice), false);
        $partialDetailData = DocumentData::fromModel($this->freshDocument($partiallyPaidInvoice), true);

        $this->assertSame($partialDetailData->payment_status, $partialListData->payment_status);
        $this->assertSame('partially_paid', $partialListData->payment_status);
        $this->assertSame('60.000', $partialListData->balance_due);
        $this->assertSame('40.000', $partialListData->amount_paid);
        $this->assertSame($partialDetailData->balance_due, $partialListData->balance_due);
        $this->assertSame($partialDetailData->amount_paid, $partialListData->amount_paid);
    }

    public function test_overpaid_invoice(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $payment = Payment::factory()->create();

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '150.00',
        ]);

        $invoice = $this->freshDocument($invoice);

        $this->assertEquals('-50.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::Overpaid, $invoice->getPaymentStatus());
    }

    public function test_multiple_payments_aggregate_correctly(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '500.00',
            'balance_due' => '500.00',
        ]);

        $payment1 = Payment::factory()->create();
        $payment2 = Payment::factory()->create();
        $payment3 = Payment::factory()->create();

        PaymentAllocation::create([
            'payment_id' => $payment1->id,
            'document_id' => $invoice->id,
            'amount' => '100.00',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment2->id,
            'document_id' => $invoice->id,
            'amount' => '200.00',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment3->id,
            'document_id' => $invoice->id,
            'amount' => '150.00',
        ]);

        $invoice = $this->freshDocument($invoice);

        $this->assertEquals('50.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::PartiallyPaid, $invoice->getPaymentStatus());
    }

    public function test_non_invoice_returns_unpaid_status(): void
    {
        $quote = Document::factory()->create([
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'total' => '100.00',
        ]);

        $this->assertEquals('0', $quote->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::Unpaid, $quote->getPaymentStatus());
    }

    public function test_outstanding_amount_with_null_total(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => null,
            'balance_due' => '0.00',
        ]);

        $this->assertEquals('0.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::Paid, $invoice->getPaymentStatus());
    }

    public function test_payment_status_after_payment_deletion(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $payment = Payment::factory()->create();

        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '100.00',
        ]);

        $invoice = $this->freshDocument($invoice);
        $this->assertEquals(PaymentStatus::Paid, $invoice->getPaymentStatus());

        // Delete allocation
        $allocation->delete();

        $invoice = $this->freshDocument($invoice);
        $this->assertEquals('100.000', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::Unpaid, $invoice->getPaymentStatus());
    }

    private function freshDocument(Document $document): Document
    {
        return Document::query()->findOrFail($document->id);
    }
}
