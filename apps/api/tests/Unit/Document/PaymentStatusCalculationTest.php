<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

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

        $this->assertEquals('100.00', $invoice->getOutstandingAmount());
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
        $invoice = $invoice->fresh();

        $this->assertEquals('40.00', $invoice->getOutstandingAmount());
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

        $invoice = $invoice->fresh();

        $this->assertEquals('0.00', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::Paid, $invoice->getPaymentStatus());
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

        $invoice = $invoice->fresh();

        $this->assertEquals('-50.00', $invoice->getOutstandingAmount());
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

        $invoice = $invoice->fresh();

        $this->assertEquals('50.00', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::PartiallyPaid, $invoice->getPaymentStatus());
    }

    public function test_non_invoice_returns_unpaid_status(): void
    {
        $quote = Document::factory()->create([
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'total' => '100.00',
        ]);

        $this->assertEquals('0.00', $quote->getOutstandingAmount());
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

        $this->assertEquals('0.00', $invoice->getOutstandingAmount());
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

        $invoice = $invoice->fresh();
        $this->assertEquals(PaymentStatus::Paid, $invoice->getPaymentStatus());

        // Delete allocation
        $allocation->delete();

        $invoice = $invoice->fresh();
        $this->assertEquals('100.00', $invoice->getOutstandingAmount());
        $this->assertEquals(PaymentStatus::Unpaid, $invoice->getPaymentStatus());
    }
}
