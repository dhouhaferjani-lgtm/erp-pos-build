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
use App\Modules\Document\Domain\Events\DocumentFullyPaid;
use App\Modules\Document\Domain\Exceptions\DocumentTransitionException;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Domain\Services\DocumentStatusMachine;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\DocumentAllocationClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
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

        // I-9 — the test's name promises revenue recognition; assert it rather
        // than only asserting that 419 and 411 net out.
        //
        // REVENUE: credited for the full invoice value at posting.
        $this->assertSame(
            '-'.$this->invoiceTotal($posted),
            $this->accountBalance(SystemAccountPurpose::ProductRevenue),
            'revenue must be CREDITED for the invoice value at posting (a credit balance reads negative)',
        );

        // VAT: `BuildsDeliveryPolicyFixtures` builds ZERO-RATED lines
        // (`dpCreateDocument()` hardcodes `tax_amount => '0.000'`), so there is
        // structurally no output-VAT leg to assert here and pretending otherwise
        // would be a vacuous assertion. What IS asserted is the invariant that
        // makes VAT recognition meaningful — the invoice's tax_amount and the
        // VAT credited agree — plus the whole-footprint balance below. VAT
        // recognition on a taxed invoice is proved by
        // `ConversionChainVatIntegrityTest`, not re-proved here.
        $this->assertSame(
            bcmul($this->invoiceTax($posted), '-1', 3),
            $this->accountBalance(SystemAccountPurpose::VatCollected),
            'output VAT credited must equal the invoice tax_amount',
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

    // ── Fiscal chain + re-post guard (fix round r1: F-1, F-2) ────────────────

    /**
     * F-1 [CRITICAL] — the chain predecessor is a FISCAL fact, not a lifecycle one.
     *
     * The predecessor query used to filter `status = Posted`. A sealed invoice
     * that had moved on to `Paid` was invisible to it, so the NEXT invoice was
     * treated as GENESIS: `previous_hash = NULL`, `chain_sequence` back to 1 —
     * a silently FORKED fiscal chain (no unique index on
     * (company_id, type, chain_sequence), no chain verifier anywhere).
     *
     * This lane makes it deterministic: `settleIfFullyPrepaid()` moves a
     * fully-prepaid invoice to `Paid` INSIDE the sealing transaction, so every
     * prepaid invoice would fork the chain for the next one.
     */
    public function test_the_fiscal_chain_continues_across_an_invoice_that_has_moved_on_to_paid(): void
    {
        $first = $this->postedPrepaidInvoice();

        $this->assertSame(DocumentStatus::Paid, $first->status, 'precondition: the sealed invoice has left Posted');
        $this->assertNotNull($first->fiscal_hash);

        $secondDeliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $second = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($second, [$secondDeliveryNote]);

        $second = app(DocumentPostingService::class)->post($second)->fresh();

        $this->assertSame(
            $first->fiscal_hash,
            $second->previous_hash,
            'the next invoice must chain onto the sealed one even though it is now Paid',
        );
        $this->assertSame(
            ($first->chain_sequence ?? 0) + 1,
            $second->chain_sequence,
            'chain_sequence must continue, never restart at genesis',
        );
    }

    /**
     * F-2 [IMPORTANT] — the in-transaction re-post guard must use the SAME
     * widened predicate as the outer probe.
     *
     * The race, made deterministic: a caller holds a STALE in-memory document
     * (still `Confirmed`) while another request posts and settles it. The outer
     * probe reads the stale object and lets the caller through; the guard inside
     * the transaction refreshes and sees `Paid`. Asking only `isPosted()` there
     * judged the invoice un-posted and sealed it a SECOND time — a second
     * chain_sequence, a second InvoicePosted, a second GL entry on one invoice.
     */
    public function test_a_stale_caller_cannot_seal_an_already_settled_invoice_twice(): void
    {
        $invoice = $this->postedPrepaidInvoice();
        $sealedHash = $invoice->fiscal_hash;
        $sealedSequence = $invoice->chain_sequence;

        // The stale replica: the same row, with the status this caller last saw.
        // Never saved — only this in-memory object is behind.
        $stale = (new Document)->newFromBuilder($invoice->getRawOriginal());
        $stale->setRawAttributes(
            array_merge($invoice->getRawOriginal(), ['status' => DocumentStatus::Confirmed->value]),
            true,
        );

        app(DocumentPostingService::class)->post($stale);

        $reread = $invoice->fresh();
        $this->assertSame($sealedHash, $reread->fiscal_hash, 'the seal must not be rewritten');
        $this->assertSame($sealedSequence, $reread->chain_sequence, 'no second chain_sequence may be minted');
        $this->assertSame(DocumentStatus::Paid, $reread->status);
    }

    /**
     * I-4 — settling at posting is a NEW way to reach `Paid`, and every other
     * way dispatches `DocumentFullyPaid` into the audit event store.
     */
    public function test_settling_at_posting_dispatches_document_fully_paid(): void
    {
        Event::fake([DocumentFullyPaid::class]);

        $invoice = $this->postedPrepaidInvoice();

        Event::assertDispatched(
            DocumentFullyPaid::class,
            fn (DocumentFullyPaid $event): bool => $event->documentId === $invoice->id,
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

    // ── Refunding a prepayment (fix round r2, treasury gate R2-C1) ───────────

    /**
     * R2-C1 [CRITICAL] — refunding an N-6 prepayment through the LIVE route.
     *
     * `refundPayment()` posts the AR-ONLY shape unconditionally
     * (`postRefundGlAndMovement()` -> `createPaymentRefundJournalEntry()`).
     * Before N-6 that was right: a customer payment credited 411 and the refund
     * debited it straight back. N-6 moved the credit to 419 and left the debit
     * on 411, so a refund produced `411 = +200 / 419 = -200` — a receivable that
     * does not exist AND an advance already paid out in cash.
     *
     * Phase 1 FAILS CLOSED rather than inventing a proration rule the reversal
     * lane deliberately refused to invent (its OQ-3). The supported path is
     * named in the refusal, and the ledger must not move.
     */
    public function test_refunding_a_prepayment_is_refused_and_moves_no_money(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->payFull($invoice)->assertCreated();

        $payment = Payment::query()->where('company_id', $this->dpCompany->id)->sole();

        $receivableBefore = $this->accountBalance(SystemAccountPurpose::CustomerReceivable);
        $advanceBefore = $this->accountBalance(SystemAccountPurpose::CustomerAdvance);

        $response = $this->actingAs($this->dpUser)->postJson("/api/v1/payments/{$payment->id}/refund", [
            'reason' => 'customer changed their mind',
            'refund_request_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('customer advance', (string) $response->json('error'));

        $this->assertSame(
            $receivableBefore,
            $this->accountBalance(SystemAccountPurpose::CustomerReceivable),
            'a refused refund must not debit a receivable that does not exist',
        );
        $this->assertSame(
            $advanceBefore,
            $this->accountBalance(SystemAccountPurpose::CustomerAdvance),
            'and it must not leave the advance standing while cash walks out',
        );
        $this->assertSame(
            '-'.$this->invoiceTotal($invoice),
            $advanceBefore,
            'precondition: the money really is in 419',
        );
    }

    /**
     * The SUPPORTED path still works, and unwinds the advance rather than a
     * receivable — so the refusal above points somewhere real.
     */
    public function test_reversing_a_prepayment_unwinds_the_advance(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->payFull($invoice)->assertCreated();

        $payment = Payment::query()->where('company_id', $this->dpCompany->id)->sole();

        $this->actingAs($this->dpUser)->postJson("/api/v1/payments/{$payment->id}/reverse", [
            'reason' => 'customer changed their mind',
            'refund_request_id' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertSame(
            '0.000',
            $this->accountBalance(SystemAccountPurpose::CustomerAdvance),
            'reversal selects the account from the ledger and clears 419',
        );
        $this->assertSame(
            '0.000',
            $this->accountBalance(SystemAccountPurpose::CustomerReceivable),
            'and never touches the receivable',
        );
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

    /**
     * CHARACTERISATION, NOT RED-FIRST — and the distinction matters, so it lives
     * here rather than only in the handback. This test passes on BASE too:
     * `AgedReceivablesService` already filters `status = Posted`, so a confirmed
     * invoice was never in aged AR. It pins that the N-6 change does not drag
     * one in, nothing more.
     */
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

    private function invoiceTax(Document $document): string
    {
        return bcadd((string) ($document->tax_amount ?? '0'), '0', 3);
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
