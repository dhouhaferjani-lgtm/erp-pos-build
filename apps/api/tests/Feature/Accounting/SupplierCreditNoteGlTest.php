<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\SupplierCreditNotePostingService;
use App\Modules\Procurement\Domain\Enums\SupplierCreditNoteReason;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SupplierCreditNoteGlTest — D1 fiscal core (mirrors C3 in reverse).
 *
 * On posting a supplier_credit_note, in ONE locked transaction, post a balanced,
 * hash-chained reversing entry:
 *   Dr SupplierPayable (401)  = credit-note gross (HT + recoverable VAT), partner-tagged
 *   Cr VatDeductible          = Σ recoverable_tax_amount
 *   Cr Inventory              = HT (balancing plug)
 *
 * Timbre is NOT reversed in Phase 1. The matrix is driven by an explicit
 * SupplierCreditNoteReason (PriceAdjustment | GoodsReturn): GoodsReturn decrements
 * quantity_invoiced on the linked PO line(s); PriceAdjustment does not.
 *
 * Every credit note links to a posted supplier invoice via source_document_id, and
 * each credit-note line resolves to a PO line referenced by that invoice
 * (FIX 1). The over-credit guard is cumulative against the invoice's actual posted
 * HT (FIX 2 — fallback mechanism; balance_due is pgsql-trigger-only and scoped to
 * customer invoices, so it is unreliable for supplier invoices).
 */
final class SupplierCreditNoteGlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $supplier;

    private Account $vatDeductibleAccount;

    private Account $stampDutyAccount;

    private Account $payableAccount;

    private Account $inventoryAccount;

    private GeneralLedgerHashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'D1 SCN GL Test Tenant',
            'slug' => 'd1-scn-gl-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'D1 SCN GL Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->vatDeductibleAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatDeductible);
        $this->stampDutyAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchaseStampDuty);
        $this->payableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);
        $this->inventoryAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Inventory);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'D1 Test Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $this->hashService = app(GeneralLedgerHashService::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a confirmed PO with one line and a POSTED supplier invoice that
     * references it (mirrors the C3 post: quantity_invoiced is set, invoice carries
     * the actual invoiced HT as its subtotal). A credit note then reverses against it.
     *
     * @param  numeric-string  $qty
     * @param  numeric-string  $unitPrice
     * @return array{poLine: DocumentLine, invoice: Document}
     */
    private function postedInvoiceWithPoLine(
        string $qty,
        string $unitPrice,
        ?Partner $partner = null,
        DocumentStatus $invoiceStatus = DocumentStatus::Posted,
    ): array {
        $partner ??= $this->supplier;
        $extended = bcmul($qty, $unitPrice, 3);

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-D1-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $extended,
            'tax_amount' => '0.000',
            'total' => $extended,
        ]);

        /** @var DocumentLine $poLine */
        $poLine = DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'D1 PO line',
            'quantity' => $qty,
            'quantity_delivered' => '0.0000',
            'quantity_received' => $qty,
            'quantity_invoiced' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $extended,
            'allocated_costs' => '0.0000',
        ]);

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => $invoiceStatus,
            'document_number' => 'SI-D1-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'source_document_id' => $po->id,
            'subtotal' => $extended,
            'tax_amount' => '0.000',
            'total' => $extended,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'D1 SI line',
            'quantity' => $qty,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'line_total' => $extended,
            'allocated_costs' => '0.0000',
            'source_line_id' => $poLine->id,
        ]);

        return ['poLine' => $poLine, 'invoice' => $invoice];
    }

    /**
     * Create a Draft supplier credit note (linked to $invoice via source_document_id)
     * with a single line linked to $poLine via source_line_id.
     *
     * @param  array{qty: numeric-string, unit_price: numeric-string, recoverable_vat: numeric-string}  $line
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $total
     * @param  numeric-string  $stampDuty
     */
    private function supplierCreditNote(
        Document $invoice,
        DocumentLine $poLine,
        SupplierCreditNoteReason $reason,
        array $line,
        string $subtotal,
        string $total,
        string $stampDuty = '0.000',
    ): Document {
        $lineTax = $line['recoverable_vat'];

        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierCreditNote,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SCN-D1-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'source_document_id' => $invoice->id,
            'subtotal' => $subtotal,
            'line_tax_amount' => $lineTax,
            'stamp_duty_amount' => $stampDuty,
            'tax_amount' => bcadd($lineTax, $stampDuty, 3),
            'total' => $total,
            'supplier_credit_note_reason' => $reason,
        ]);

        DocumentLine::create([
            'document_id' => $creditNote->id,
            'line_number' => 1,
            'description' => 'D1 SCN line',
            'quantity' => $line['qty'],
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => $line['unit_price'],
            'line_total' => bcmul($line['qty'], $line['unit_price'], 3),
            'allocated_costs' => '0.0000',
            'tax_amount' => $lineTax,
            'tax_recoverable' => true,
            'recoverable_tax_amount' => $lineTax,
            'non_recoverable_tax_amount' => '0.000',
            'source_line_id' => $poLine->id,
        ]);

        $creditNote->load('lines');

        return $creditNote;
    }

    private function service(): SupplierCreditNotePostingService
    {
        return app(SupplierCreditNotePostingService::class);
    }

    private function freshLine(DocumentLine $line): DocumentLine
    {
        return DocumentLine::findOrFail($line->id);
    }

    private function freshDoc(Document $doc): Document
    {
        return Document::findOrFail($doc->id);
    }

    private function creditEntry(Document $creditNote): JournalEntry
    {
        return JournalEntry::where('source_type', 'supplier_credit_note')
            ->where('source_id', $creditNote->id)
            ->firstOrFail()
            ->load('lines');
    }

    private function legOn(JournalEntry $entry, Account $account): ?JournalLine
    {
        return $entry->lines->firstWhere('account_id', $account->id);
    }

    private function assertBalanced(JournalEntry $entry): void
    {
        $debit = '0.000';
        $credit = '0.000';
        foreach ($entry->lines as $l) {
            $debit = bcadd($debit, $l->debit, 3);
            $credit = bcadd($credit, $l->credit, 3);
        }
        $this->assertSame('0.000', bcsub($debit, $credit, 3), 'Entry must balance exactly at scale 3');
    }

    // =========================================================================
    // 1. Price-only reduction (PriceAdjustment): no quantity_invoiced effect.
    // =========================================================================

    public function test_price_adjustment_reverses_vat_and_inventory_without_touching_quantity_invoiced(): void
    {
        // Invoice: 5 @ 10.000 (subtotal 50.000). Price reduction of 1.000/unit → HT 5.000.
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');
        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::PriceAdjustment,
            ['qty' => '5.0000', 'unit_price' => '1.000', 'recoverable_vat' => '0.950'],
            subtotal: '5.000',
            total: '5.950',
        );

        $this->service()->post($creditNote);

        $entry = $this->creditEntry($creditNote);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        // Three legs only: Dr401, CrVAT, CrInventory. No timbre, no 408.
        $this->assertCount(3, $entry->lines);

        // Dr 401 = gross, partner-tagged, REDUCES payable.
        $dr401 = $this->legOn($entry, $this->payableAccount);
        $this->assertNotNull($dr401);
        $this->assertSame('5.950', $dr401->debit);
        $this->assertSame('0.000', $dr401->credit);
        $this->assertSame($this->supplier->id, $dr401->partner_id);

        // Cr VatDeductible = recoverable VAT.
        $crVat = $this->legOn($entry, $this->vatDeductibleAccount);
        $this->assertNotNull($crVat);
        $this->assertSame('0.000', $crVat->debit);
        $this->assertSame('0.950', $crVat->credit);
        $this->assertNull($crVat->partner_id);

        // Cr Inventory = HT (plug).
        $crInv = $this->legOn($entry, $this->inventoryAccount);
        $this->assertNotNull($crInv);
        $this->assertSame('0.000', $crInv->debit);
        $this->assertSame('5.000', $crInv->credit);
        $this->assertNull($crInv->partner_id);

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));

        // PriceAdjustment does NOT touch quantity_invoiced.
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);

        $this->assertSame(DocumentStatus::Posted, $this->freshDoc($creditNote)->status);
    }

    // =========================================================================
    // 2. Returned goods (GoodsReturn): quantity_invoiced decremented.
    // =========================================================================

    public function test_goods_return_reverses_gl_and_decrements_quantity_invoiced(): void
    {
        // Invoice: 5 @ 10.000 (subtotal 50.000). Return 2 units → HT 20.000.
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');
        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );

        $this->service()->post($creditNote);

        $entry = $this->creditEntry($creditNote);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        $dr401 = $this->legOn($entry, $this->payableAccount);
        $this->assertNotNull($dr401);
        $this->assertSame('23.800', $dr401->debit);
        $this->assertSame($this->supplier->id, $dr401->partner_id);

        $crVat = $this->legOn($entry, $this->vatDeductibleAccount);
        $this->assertNotNull($crVat);
        $this->assertSame('3.800', $crVat->credit);

        $crInv = $this->legOn($entry, $this->inventoryAccount);
        $this->assertNotNull($crInv);
        $this->assertSame('20.000', $crInv->credit);

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));

        // quantity_invoiced reduced 5 → 3 (reopens the PO line for re-invoicing).
        $this->assertSame('3.0000', $this->freshLine($poLine)->quantity_invoiced);
    }

    // =========================================================================
    // 3. Over-credit blocked (qty guard): returning more than invoiced THROWS.
    //    Isolated from the cumulative-HT guard via a reduced credit price.
    // =========================================================================

    public function test_goods_return_over_credit_quantity_throws_and_rolls_back(): void
    {
        // 5 invoiced; return 6 units (qty over-credit) at a reduced price 5.000 so the
        // credit HT (30.000) stays within the invoice HT (50.000) — isolating the qty guard.
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');
        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '6.0000', 'unit_price' => '5.000', 'recoverable_vat' => '5.700'],
            subtotal: '30.000',
            total: '35.700',
        );

        $threw = false;
        try {
            $this->service()->post($creditNote);
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Returning more units than invoiced must throw');

        // Rolled back: no JE, quantity_invoiced unchanged, still Draft.
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $creditNote->id)->count());
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($creditNote)->status);
    }

    // =========================================================================
    // 4. Idempotency: a duplicate post is a no-op.
    // =========================================================================

    public function test_idempotent_second_post_is_noop(): void
    {
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');
        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );

        $this->service()->post($creditNote);
        $this->service()->post($this->freshDoc($creditNote));

        $count = JournalEntry::where('source_type', 'supplier_credit_note')
            ->where('source_id', $creditNote->id)
            ->count();
        $this->assertSame(1, $count, 'Second post must not create a duplicate entry');

        // quantity_invoiced decremented exactly once: 5 → 3 (not 1).
        $this->assertSame('3.0000', $this->freshLine($poLine)->quantity_invoiced);
    }

    // =========================================================================
    // 5. Timbre is NOT reversed in Phase 1.
    // =========================================================================

    public function test_timbre_is_not_reversed(): void
    {
        // Credit note carries a stamp_duty_amount, but no PurchaseStampDuty leg is posted.
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');
        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
            stampDuty: '0.600',
        );

        $this->service()->post($creditNote);
        $entry = $this->creditEntry($creditNote);

        // No PurchaseStampDuty leg — timbre is not reversed.
        $this->assertNull($this->legOn($entry, $this->stampDutyAccount), 'Timbre must NOT be reversed in Phase 1');

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 6. FIX 1: an unlinked credit-note line (source_line_id null) THROWS, posts nothing.
    // =========================================================================

    public function test_unlinked_credit_note_line_throws_and_posts_nothing(): void
    {
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');

        // Document totals INCLUDE the unlinked line so the poster's balance invariant
        // (total == HT + VAT) holds — the ONLY defect is the unlinked line, so a green
        // here would prove the linkage guard (FIX 1), not the balance invariant.
        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '30.000',
            total: '35.700',
        );

        // Add a SECOND line with NO source_line_id (unlinked) — must abort the whole post.
        DocumentLine::create([
            'document_id' => $creditNote->id,
            'line_number' => 2,
            'description' => 'D1 SCN unlinked line',
            'quantity' => '1.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '10.000',
            'allocated_costs' => '0.0000',
            'tax_amount' => '1.900',
            'tax_recoverable' => true,
            'recoverable_tax_amount' => '1.900',
            'non_recoverable_tax_amount' => '0.000',
            'source_line_id' => null,
        ]);
        $creditNote->load('lines');

        $threw = false;
        try {
            $this->service()->post($creditNote);
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'An unlinked credit-note line must abort the entire post');

        // Nothing posted, no decrement, still Draft.
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $creditNote->id)->count());
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($creditNote)->status);
    }

    // =========================================================================
    // 7. FIX 1: a line linked to a PO line NOT on the linked invoice THROWS.
    // =========================================================================

    public function test_line_linked_to_foreign_po_line_throws_and_posts_nothing(): void
    {
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');

        // A DIFFERENT PO/invoice — its PO line is not referenced by $invoice.
        ['poLine' => $foreignPoLine] = $this->postedInvoiceWithPoLine('5.0000', '10.000');

        $creditNote = $this->supplierCreditNote(
            $invoice,
            $foreignPoLine, // line points at a PO line that the linked invoice does NOT reference
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );

        $threw = false;
        try {
            $this->service()->post($creditNote);
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A credit-note line not belonging to the linked invoice must abort the post');

        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $creditNote->id)->count());
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame('5.0000', $this->freshLine($foreignPoLine)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($creditNote)->status);
    }

    // =========================================================================
    // 8. FIX 2: a SINGLE credit exceeding the invoice's actual HT THROWS.
    // =========================================================================

    public function test_single_credit_exceeding_invoice_ht_throws_and_rolls_back(): void
    {
        // Invoice HT = 50.000. A PriceAdjustment crediting HT 60.000 > 50.000 must throw.
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');
        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::PriceAdjustment,
            ['qty' => '5.0000', 'unit_price' => '12.000', 'recoverable_vat' => '11.400'],
            subtotal: '60.000',
            total: '71.400',
        );

        $threw = false;
        try {
            $this->service()->post($creditNote);
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Crediting more HT than the invoice carried must throw');

        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $creditNote->id)->count());
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($creditNote)->status);
    }

    // =========================================================================
    // 9. FIX 2: a SECOND credit that, cumulatively, exceeds the invoice HT THROWS.
    // =========================================================================

    public function test_cumulative_second_credit_exceeding_invoice_ht_throws_and_rolls_back(): void
    {
        // Invoice HT = 50.000. First PriceAdjustment credits HT 40.000 (ok).
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');

        $first = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::PriceAdjustment,
            ['qty' => '5.0000', 'unit_price' => '8.000', 'recoverable_vat' => '7.600'],
            subtotal: '40.000',
            total: '47.600',
        );
        $this->service()->post($first);
        $this->assertSame(DocumentStatus::Posted, $this->freshDoc($first)->status);

        // Second PriceAdjustment credits HT 20.000 → cumulative 60.000 > 50.000 → throws.
        $second = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::PriceAdjustment,
            ['qty' => '5.0000', 'unit_price' => '4.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );

        $threw = false;
        try {
            $this->service()->post($second);
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A second credit pushing cumulative HT past the invoice HT must throw');

        // Only the first credit posted; the second rolled back.
        $this->assertSame(1, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $first->id)->count());
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $second->id)->count());
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($second)->status);
    }

    // =========================================================================
    // 10. FIX 3: multi-line credit note aggregates returned qty + HT correctly.
    // =========================================================================

    public function test_multi_line_credit_note_aggregates_quantity_and_ht(): void
    {
        // Invoice: 5 @ 10.000 (subtotal 50.000). Two credit lines (1 + 2 = 3 returned) on one PO line.
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');

        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierCreditNote,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SCN-D1-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'source_document_id' => $invoice->id,
            'subtotal' => '30.000',
            'line_tax_amount' => '5.700',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => '5.700',
            'total' => '35.700',
            'supplier_credit_note_reason' => SupplierCreditNoteReason::GoodsReturn,
        ]);
        foreach ([['n' => 1, 'q' => '1.0000', 'v' => '1.900'], ['n' => 2, 'q' => '2.0000', 'v' => '3.800']] as $l) {
            DocumentLine::create([
                'document_id' => $creditNote->id,
                'line_number' => $l['n'],
                'description' => 'D1 SCN multi line',
                'quantity' => $l['q'],
                'quantity_delivered' => '0.0000',
                'quantity_received' => '0.0000',
                'quantity_invoiced' => '0.0000',
                'unit_price' => '10.000',
                'line_total' => bcmul($l['q'], '10.000', 3),
                'allocated_costs' => '0.0000',
                'tax_amount' => $l['v'],
                'tax_recoverable' => true,
                'recoverable_tax_amount' => $l['v'],
                'non_recoverable_tax_amount' => '0.000',
                'source_line_id' => $poLine->id,
            ]);
        }
        $creditNote->load('lines');

        $this->service()->post($creditNote);
        $entry = $this->creditEntry($creditNote);

        // quantity_invoiced decremented by the SUM (1 + 2 = 3): 5 → 2.
        $this->assertSame('2.0000', $this->freshLine($poLine)->quantity_invoiced);

        // Cr Inventory = aggregate HT (30.000); Cr VAT = aggregate recoverable (5.700).
        $crInv = $this->legOn($entry, $this->inventoryAccount);
        $this->assertNotNull($crInv);
        $this->assertSame('30.000', $crInv->credit);
        $crVat = $this->legOn($entry, $this->vatDeductibleAccount);
        $this->assertNotNull($crVat);
        $this->assertSame('5.700', $crVat->credit);

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 11. FIX 3: recoverable VAT comes from document_lines.recoverable_tax_amount,
    //     NOT documents.tax_amount (which includes timbre).
    // =========================================================================

    public function test_vat_leg_sourced_from_line_recoverable_not_document_tax_amount(): void
    {
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');

        // documents.tax_amount = recoverable 3.800 + timbre 0.600 = 4.400 (≠ line recoverable).
        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
            stampDuty: '0.600',
        );

        // Precondition: the document's tax_amount deliberately differs from line recoverable.
        $this->assertSame('4.400', $this->freshDoc($creditNote)->tax_amount);

        $this->service()->post($creditNote);
        $entry = $this->creditEntry($creditNote);

        // Cr VatDeductible == sum of line recoverable (3.800), NOT documents.tax_amount (4.400).
        $crVat = $this->legOn($entry, $this->vatDeductibleAccount);
        $this->assertNotNull($crVat);
        $this->assertSame('3.800', $crVat->credit);
        $this->assertNotSame('4.400', $crVat->credit);

        // Timbre is not reversed: no PurchaseStampDuty leg.
        $this->assertNull($this->legOn($entry, $this->stampDutyAccount));

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 12. FIX 1 (re-review): cross-supplier — credit note linking ANOTHER
    //     supplier's invoice THROWS (partner_id must match), posts nothing.
    // =========================================================================

    public function test_credit_note_linking_another_suppliers_invoice_throws(): void
    {
        $supplierB = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'D1 Test Supplier B',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        // Supplier B's posted invoice + PO line.
        ['poLine' => $bPoLine, 'invoice' => $bInvoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000', $supplierB);

        // Credit note for Supplier A (this->supplier) linking Supplier B's invoice.
        $creditNote = $this->supplierCreditNote(
            $bInvoice,
            $bPoLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );
        // supplierCreditNote() tags partner_id = this->supplier (A) ≠ $bInvoice->partner_id (B).

        $threw = false;
        try {
            $this->service()->post($creditNote);
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A credit note must not link an invoice belonging to a different supplier');

        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $creditNote->id)->count());
        $this->assertSame('5.0000', $this->freshLine($bPoLine)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($creditNote)->status);
    }

    // =========================================================================
    // 13. FIX 2 (re-review): linking a NON-POSTED (Draft) supplier invoice THROWS.
    // =========================================================================

    public function test_credit_note_linking_non_posted_invoice_throws(): void
    {
        // Linked supplier invoice is Draft (not Posted) — a credit reverses a posted liability.
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000', null, DocumentStatus::Draft);

        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );

        $threw = false;
        try {
            $this->service()->post($creditNote);
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A credit note must not reverse a non-posted supplier invoice');

        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $creditNote->id)->count());
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($creditNote)->status);
    }

    // =========================================================================
    // 14. FIX 3 (re-review): the input document must be a SupplierCreditNote.
    // =========================================================================

    public function test_non_supplier_credit_note_document_is_rejected(): void
    {
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');

        // Otherwise-valid SCN data, but the document type is wrong.
        $document = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );
        $document->type = DocumentType::CreditNote; // customer credit note — NOT supplier
        $document->save();

        $threw = false;
        try {
            $this->service()->post($this->freshDoc($document));
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Only a SupplierCreditNote document may be posted by this service');

        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $document->id)->count());
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);
    }

    // =========================================================================
    // 15. FIX 3 (re-review): a Cancelled credit note is rejected (not Draft/Posted).
    // =========================================================================

    public function test_cancelled_credit_note_is_rejected(): void
    {
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');

        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );
        $creditNote->status = DocumentStatus::Cancelled;
        $creditNote->save();

        $threw = false;
        try {
            $this->service()->post($this->freshDoc($creditNote));
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A cancelled credit note must not be posted');

        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $creditNote->id)->count());
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Cancelled, $this->freshDoc($creditNote)->status);
    }

    // =========================================================================
    // 16. FIX 3 (re-review): already-Posted retry still no-ops (idempotency preserved).
    // =========================================================================

    public function test_already_posted_retry_is_noop(): void
    {
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');

        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );

        $this->service()->post($creditNote);
        $this->assertSame(DocumentStatus::Posted, $this->freshDoc($creditNote)->status);

        // Re-post the now-Posted credit note (entry already exists) → no-op, no throw.
        $this->service()->post($this->freshDoc($creditNote));

        $this->assertSame(1, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $creditNote->id)->count());
        $this->assertSame('3.0000', $this->freshLine($poLine)->quantity_invoiced);
    }

    // =========================================================================
    // 17. Boundary: cumulative HT EXACTLY equal to invoice HT POSTS (full reversal).
    // =========================================================================

    public function test_full_reversal_equal_to_invoice_ht_posts(): void
    {
        // Invoice HT = 50.000. A PriceAdjustment crediting HT 50.000 (equal) must POST.
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');
        $creditNote = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::PriceAdjustment,
            ['qty' => '5.0000', 'unit_price' => '10.000', 'recoverable_vat' => '9.500'],
            subtotal: '50.000',
            total: '59.500',
        );

        $this->service()->post($creditNote);

        $entry = $this->creditEntry($creditNote);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $crInv = $this->legOn($entry, $this->inventoryAccount);
        $this->assertNotNull($crInv);
        $this->assertSame('50.000', $crInv->credit);
        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 18. Mixed reasons: cumulative HT summed across GoodsReturn + PriceAdjustment.
    //     Two within-bound credits POST; a third exceeding the bound is REJECTED.
    // =========================================================================

    public function test_mixed_reasons_cumulative_across_reasons(): void
    {
        // Invoice HT = 50.000.
        ['poLine' => $poLine, 'invoice' => $invoice] = $this->postedInvoiceWithPoLine('5.0000', '10.000');

        // CN1 GoodsReturn HT 20.000 (return 2 @ 10): cumulative 20 ≤ 50 → posts; invoiced 5 → 3.
        $cn1 = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );
        $this->service()->post($cn1);
        $this->assertSame('3.0000', $this->freshLine($poLine)->quantity_invoiced);

        // CN2 PriceAdjustment HT 20.000 (5 @ 4 reduction): cumulative 20 + 20 = 40 ≤ 50 → posts.
        $cn2 = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::PriceAdjustment,
            ['qty' => '5.0000', 'unit_price' => '4.000', 'recoverable_vat' => '3.800'],
            subtotal: '20.000',
            total: '23.800',
        );
        $this->service()->post($cn2);
        // PriceAdjustment did not touch quantity_invoiced.
        $this->assertSame('3.0000', $this->freshLine($poLine)->quantity_invoiced);

        // Both posted.
        $this->assertSame(1, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $cn1->id)->count());
        $this->assertSame(1, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $cn2->id)->count());

        // CN3 PriceAdjustment HT 15.000: cumulative 40 + 15 = 55 > 50 → REJECTED.
        $cn3 = $this->supplierCreditNote(
            $invoice,
            $poLine,
            SupplierCreditNoteReason::PriceAdjustment,
            ['qty' => '5.0000', 'unit_price' => '3.000', 'recoverable_vat' => '2.850'],
            subtotal: '15.000',
            total: '17.850',
        );

        $threw = false;
        try {
            $this->service()->post($cn3);
        } catch (\DomainException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'A third credit pushing cumulative HT (across reasons) past the invoice HT must throw');
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_credit_note')->where('source_id', $cn3->id)->count());
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($cn3)->status);
    }
}
