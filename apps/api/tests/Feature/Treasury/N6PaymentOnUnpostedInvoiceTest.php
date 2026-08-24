<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\Reports\AgedReceivablesService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Exceptions\DocumentTransitionException;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Domain\Services\DocumentStatusMachine;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\DocumentAllocationClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * N-6 / B-20 — money collected on a CONFIRMED (unposted) invoice.
 *
 * The campaign state this pins: a payment on a confirmed invoice used to credit
 * the receivable (411) that posting had not yet created, and flip the invoice to
 * `Paid` — a status `DocumentPostingService::post()` refuses, so the invoice
 * could never be posted, never sealed, and its revenue and VAT were never
 * recognised (INV-2026-0003).
 *
 * @see DocumentAllocationClassifier
 * @see DocumentStatusMachine
 */
final class N6PaymentOnUnpostedInvoiceTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    private PaymentMethod $cashMethod;

    private PaymentRepository $cashRegister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDeliveryPolicyFixtures('TN');

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $bank = Account::findByPurposeOrFail($this->dpCompany->id, SystemAccountPurpose::Bank);

        $this->cashRegister = PaymentRepository::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'code' => 'CASH-01',
            'name' => 'Main Cash Register',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $bank->id,
            'is_active' => true,
        ]);
    }

    // ── The N-6 replay ───────────────────────────────────────────────────────

    public function test_payment_on_a_confirmed_invoice_books_419_and_leaves_the_lifecycle_confirmed(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);

        $this->payFull($invoice)->assertCreated();

        $invoice->refresh();

        $this->assertSame(
            DocumentStatus::Confirmed,
            $invoice->status,
            'A prepayment must NOT move the lifecycle — the invoice still owes its posting.',
        );
        $this->assertNull($invoice->fiscal_hash, 'Nothing may seal an invoice outside posting.');

        $this->assertSame(
            '0.000',
            $this->accountBalance(SystemAccountPurpose::CustomerReceivable),
            'The receivable must be untouched: posting is what creates it.',
        );
        $this->assertSame(
            '-'.$this->invoiceTotal($invoice),
            $this->accountBalance(SystemAccountPurpose::CustomerAdvance),
            'The money must sit in 419 as a customer advance (credit balance).',
        );

        $allocation = PaymentAllocation::query()->where('document_id', $invoice->id)->firstOrFail();
        $this->assertTrue($allocation->booked_as_advance, 'The allocation must record that it was booked to 419.');
        $this->assertNotNull($allocation->advance_journal_entry_id);
        $this->assertNull($allocation->advance_cleared_at);
    }

    public function test_posting_a_prepaid_invoice_clears_419_recognises_revenue_and_settles_the_lifecycle(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$deliveryNote]);

        $this->payFull($invoice)->assertCreated();

        $posted = app(DocumentPostingService::class)->post($invoice->fresh());

        $this->assertSame(DocumentStatus::Paid, $posted->status, 'Paid is reached THROUGH Posted, at posting time.');
        $this->assertNotNull($posted->fiscal_hash, 'Posting seals the invoice.');
        $this->assertSame(FiscalStatus::Sealed, $posted->fiscal_status);

        $this->assertSame(
            '0.000',
            $this->accountBalance(SystemAccountPurpose::CustomerAdvance),
            'The advance must be fully discharged once the receivable exists.',
        );
        $this->assertSame(
            '0.000',
            $this->accountBalance(SystemAccountPurpose::CustomerReceivable),
            'Invoice debit and clearing credit must net to zero.',
        );

        $this->assertGreaterThan(
            0,
            JournalEntry::query()
                ->where('company_id', $this->dpCompany->id)
                ->where('source_type', 'prepayment_application')
                ->where('source_id', $posted->id)
                ->count(),
            'A clearing entry (Dr 419 / Cr 411) must exist for this invoice.',
        );

        $allocation = PaymentAllocation::query()->where('document_id', $posted->id)->firstOrFail();
        $this->assertNotNull($allocation->advance_cleared_at, 'The advance must be stamped cleared exactly once.');
    }

    public function test_posting_clears_the_advance_only_once(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$deliveryNote]);

        $this->payFull($invoice)->assertCreated();

        $posting = app(DocumentPostingService::class);
        $posted = $posting->post($invoice->fresh());
        // Idempotent re-post: post() short-circuits on an already-posted document.
        $posting->post($posted->fresh());

        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('company_id', $this->dpCompany->id)
                ->where('source_type', 'prepayment_application')
                ->where('source_id', $posted->id)
                ->count(),
            'A second clearing entry would drain another advance of the same partner.',
        );
    }

    // ── The refusals ─────────────────────────────────────────────────────────

    public function test_manual_allocation_to_a_draft_invoice_is_refused_with_422(): void
    {
        $draft = $this->dpConfirmedInvoice([$this->dpPhysicalLine()], ['status' => DocumentStatus::Draft]);

        $response = $this->payFull($draft);

        $response->assertStatus(422);
        $this->assertSame('DOCUMENT_NOT_ALLOCATABLE', $response->json('error.code'));
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $draft->id)->count());
    }

    public function test_allocation_to_a_credit_note_is_refused_with_422(): void
    {
        $creditNote = $this->dpConfirmedCreditNote([$this->dpPhysicalLine()]);

        $this->payFull($creditNote)->assertStatus(422);
    }

    public function test_the_status_service_refuses_confirmed_to_paid(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);

        $this->expectException(DocumentTransitionException::class);

        app(DocumentStatusService::class)->markPaid($invoice);
    }

    public function test_the_status_service_refuses_to_reopen_a_never_posted_invoice_to_posted(): void
    {
        // The legacy shape the repair command exists for: Paid with no seal.
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $invoice->forceFill(['status' => DocumentStatus::Paid])->save();

        $result = app(DocumentStatusService::class)->reopenFromPaid($invoice, ['balance_due' => '10.000']);

        $this->assertSame(
            DocumentStatus::Paid,
            $result->fresh()->status,
            'A refund must never manufacture a Posted invoice that was never sealed.',
        );
    }

    public function test_reopen_from_paid_restores_posted_on_a_genuinely_sealed_invoice(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$deliveryNote]);

        $posted = app(DocumentPostingService::class)->post($invoice);
        app(DocumentStatusService::class)->markPaid($posted, ['balance_due' => '0.000']);

        $reopened = app(DocumentStatusService::class)->reopenFromPaid($posted->fresh(), ['balance_due' => '50.000']);

        $this->assertSame(DocumentStatus::Posted, $reopened->fresh()->status);
    }

    // ── Order-originated prepayments must not be cleared twice ───────────────

    public function test_a_prepayment_transferred_from_an_order_is_not_cleared_again_at_posting(): void
    {
        $order = $this->dpConfirmedInvoice([$this->dpPhysicalLine()], [
            'type' => DocumentType::SalesOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
        ]);

        $this->payFull($order)->assertCreated();

        $orderAllocation = PaymentAllocation::query()->where('document_id', $order->id)->firstOrFail();
        $this->assertTrue($orderAllocation->booked_as_advance);

        // Stand in for `SalesOrderToInvoiceConverter::transferPrepayments()`: the
        // allocation is re-pointed at the invoice AND stamped cleared, because
        // the converter clears the advance at CONVERSION time.
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$deliveryNote]);

        $orderAllocation->update([
            'document_id' => $invoice->id,
            'advance_cleared_at' => now(),
        ]);

        $posted = app(DocumentPostingService::class)->post($invoice->fresh());

        $this->assertSame(
            0,
            JournalEntry::query()
                ->where('company_id', $this->dpCompany->id)
                ->where('source_type', 'prepayment_application')
                ->where('source_id', $posted->id)
                ->count(),
            'An advance already cleared at conversion must not be cleared a second time at posting.',
        );
    }

    // ── AR readers ───────────────────────────────────────────────────────────

    public function test_aged_receivables_reads_zero_while_the_advance_is_open(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->payFull($invoice)->assertCreated();

        $report = app(AgedReceivablesService::class)
            ->generate($this->dpCompany->id);

        $this->assertSame('0.000', bcadd($report->grand_total, '0', 3));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A fully-prepaid invoice, posted: sealed AND settled to `Paid` in one
     * transaction — the state this lane introduces, and the one the chain and
     * the re-post guard both have to cope with.
     */
    private function postedPrepaidInvoice(): Document
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$deliveryNote]);

        $this->payFull($invoice)->assertCreated();

        return app(DocumentPostingService::class)->post($invoice->fresh())->fresh();
    }

    private function payFull(Document $document): TestResponse
    {
        return $this->actingAs($this->dpUser)->postJson('/api/v1/payments', [
            'partner_id' => $this->dpPartner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => $this->invoiceTotal($document),
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $document->id, 'amount' => $this->invoiceTotal($document)],
            ],
        ]);
    }

    private function invoiceTotal(Document $document): string
    {
        return bcadd((string) ($document->total ?? '0'), '0', 3);
    }

    /**
     * The POSTED balance (debit − credit) of the company's account for a
     * purpose, at money scale. A credit-normal account (419) reads negative.
     */
    private function accountBalance(SystemAccountPurpose $purpose): string
    {
        $account = Account::findByPurposeOrFail($this->dpCompany->id, $purpose);

        $lines = JournalLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', function ($query): void {
                $query->where('company_id', $this->dpCompany->id)
                    ->where('status', JournalEntryStatus::Posted);
            })
            ->get();

        $balance = '0.000';
        foreach ($lines as $line) {
            $balance = bcadd($balance, bcsub((string) $line->debit, (string) $line->credit, 3), 3);
        }

        return $balance;
    }
}
