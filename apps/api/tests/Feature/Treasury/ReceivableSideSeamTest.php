<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Exceptions\DocumentNotAllocatableException;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\Services\MultiPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Treasury\Concerns\PaymentApplicabilityScaffold;
use Tests\TestCase;

/**
 * C-0a0 fix round r1 / gate F-3 + F-4 — `classifyReceivableSide()` proven through
 * a real AR-only writer, not just as a unit.
 *
 * The gate's finding: the seam that guards 8 of the 12 writers had ZERO
 * behavioural coverage, and it is currently unreachable through the HTTP surface
 * because every AR-only entry point rejects supplier invoices EARLIER with an
 * older, differently-coded guard (`MultiPaymentController::rejectSupplierInvoice()`
 * at `:174`/`:387`, `PaymentAllocationService::rejectSupplierInvoiceAllocation()`
 * at `:200`/`:724`, `PaymentController::rejectSupplierInvoiceInMultiline()` at
 * `:1478`/`:1806`). Those older guards are load-bearing today and are NOT being
 * removed here (gate ruling on R-C0a0-5) — so this suite reaches the seam the
 * only honest way: by calling the SERVICE directly, exactly as it will be reached
 * the day a caller without a hand-written type guard is added.
 *
 * F-4 is the second half: the refusal must not claim a posted supplier invoice is
 * an "outward document type" and hand the operator credit-note advice. It now
 * carries `payable_not_settleable_here` and points at the supplier payment flow.
 */
final class ReceivableSideSeamTest extends TestCase
{
    use PaymentApplicabilityScaffold;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPaymentApplicabilityFixture('recv-seam');
    }

    public function test_the_split_payment_service_refuses_a_posted_supplier_invoice_with_the_payable_reason(): void
    {
        $supplierInvoice = $this->postedSupplierInvoice('100.000');

        try {
            app(MultiPaymentService::class)->createSplitPayment(
                $supplierInvoice,
                [
                    ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->repository->id, 'amount' => '50.000'],
                    ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->repository->id, 'amount' => '50.000'],
                ],
                $this->user->id,
            );
            $this->fail('a posted supplier invoice must not be settleable through the AR split-payment path');
        } catch (DocumentNotAllocatableException $e) {
            $this->assertSame('payable_not_settleable_here', $e->reason->value);
        }

        $this->assertSame(
            0,
            PaymentAllocation::query()->where('document_id', $supplierInvoice->id)->count(),
            'no allocation row may survive the refusal',
        );
    }

    public function test_the_deposit_application_service_refuses_a_posted_supplier_invoice(): void
    {
        $supplierInvoice = $this->postedSupplierInvoice('100.000');
        $deposit = $this->makeUnallocatedDeposit('100.000', $this->vendor);

        try {
            app(MultiPaymentService::class)->applyDepositToDocument($deposit, $supplierInvoice, '100.000');
            $this->fail('a posted supplier invoice must not absorb a customer deposit');
        } catch (DocumentNotAllocatableException $e) {
            $this->assertSame('payable_not_settleable_here', $e->reason->value);
        }

        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $supplierInvoice->id)->count());
    }

    /**
     * The seam must not become a blanket refusal: the AR documents it exists to
     * let through still go through, with the right treatment.
     */
    public function test_the_split_payment_service_still_admits_a_posted_invoice(): void
    {
        $invoice = $this->makeDocument(DocumentType::Invoice, DocumentStatus::Posted, '100.000', $this->customer, 'INV');

        app(MultiPaymentService::class)->createSplitPayment(
            $invoice,
            [
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->repository->id, 'amount' => '50.000'],
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->repository->id, 'amount' => '50.000'],
            ],
            $this->user->id,
        );

        $this->assertSame(
            0,
            bccomp((string) PaymentAllocation::query()->where('document_id', $invoice->id)->sum('amount'), '100.000', 3),
        );
        $this->assertSame(
            0,
            PaymentAllocation::query()->where('document_id', $invoice->id)->where('booked_as_advance', true)->count(),
            'a POSTED invoice clears a receivable — it is not an advance',
        );
    }

    public function test_the_split_payment_service_books_a_confirmed_invoice_as_an_advance(): void
    {
        $invoice = $this->makeDocument(DocumentType::Invoice, DocumentStatus::Confirmed, '100.000', $this->customer, 'INV');

        app(MultiPaymentService::class)->createSplitPayment(
            $invoice,
            [
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->repository->id, 'amount' => '50.000'],
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->repository->id, 'amount' => '50.000'],
            ],
            $this->user->id,
        );

        $this->assertSame(
            2,
            PaymentAllocation::query()->where('document_id', $invoice->id)->where('booked_as_advance', true)->count(),
            'a CONFIRMED invoice has no 411 to clear — every split line is an advance',
        );
    }

    /**
     * A posted supplier invoice with a real Cr-401 journal entry — the shape the
     * supplier-aware `PaymentController::store()` branch requires, so the refusal
     * under test cannot be dismissed as "that document was never payable anyway".
     */
    private function postedSupplierInvoice(string $total): Document
    {
        $document = $this->makeDocument(DocumentType::SupplierInvoice, DocumentStatus::Posted, $total, $this->vendor, 'SI');

        $payable = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);
        $expense = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchaseExpenses);

        $entry = JournalEntry::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-SI-'.Str::upper(Str::random(6)),
            'entry_date' => now()->toDateString(),
            'description' => 'Supplier invoice posting (fixture)',
            'source_type' => 'supplier_invoice',
            'source_id' => $document->id,
            'status' => JournalEntryStatus::Posted,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $expense->id,
            'partner_id' => $this->vendor->id,
            'debit' => $total,
            'credit' => '0.000',
            'description' => 'Purchases',
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $payable->id,
            'partner_id' => $this->vendor->id,
            'debit' => '0.000',
            'credit' => $total,
            'description' => 'Supplier payable',
        ]);

        return $document->refresh();
    }
}
