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
     * Create a confirmed PO with one already-invoiced line (so a credit note can
     * reverse against it). quantity_invoiced is pre-set to simulate prior invoicing.
     *
     * @param  numeric-string  $qty
     * @param  numeric-string  $unitPrice
     * @param  numeric-string  $quantityInvoiced
     */
    private function invoicedPoLine(string $qty, string $unitPrice, string $quantityInvoiced): DocumentLine
    {
        $extended = bcmul($qty, $unitPrice, 3);

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
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

        /** @var DocumentLine $line */
        $line = DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'D1 PO line',
            'quantity' => $qty,
            'quantity_delivered' => '0.0000',
            'quantity_received' => $qty,
            'quantity_invoiced' => $quantityInvoiced,
            'unit_price' => $unitPrice,
            'line_total' => $extended,
            'allocated_costs' => '0.0000',
        ]);

        return $line;
    }

    /**
     * Create a Draft supplier credit note with a single line linked to $poLine.
     *
     * @param  array{qty: numeric-string, unit_price: numeric-string, recoverable_vat: numeric-string}  $line
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $total
     * @param  numeric-string  $stampDuty
     */
    private function supplierCreditNote(
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
        // PO: 5 @ 10.000, fully invoiced. Price reduction of 1.000/unit → HT 5.000.
        $poLine = $this->invoicedPoLine('5.0000', '10.000', '5.0000');
        $creditNote = $this->supplierCreditNote(
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
        // PO: 5 @ 10.000, fully invoiced. Return 2 units → HT 20.000.
        $poLine = $this->invoicedPoLine('5.0000', '10.000', '5.0000');
        $creditNote = $this->supplierCreditNote(
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
    // 3. Over-credit blocked: returning more than invoiced THROWS + rolls back.
    // =========================================================================

    public function test_over_credit_beyond_invoiced_throws_and_rolls_back(): void
    {
        // Only 5 invoiced; credit note tries to return 6 → would drive < 0.
        $poLine = $this->invoicedPoLine('5.0000', '10.000', '5.0000');
        $creditNote = $this->supplierCreditNote(
            $poLine,
            SupplierCreditNoteReason::GoodsReturn,
            ['qty' => '6.0000', 'unit_price' => '10.000', 'recoverable_vat' => '11.400'],
            subtotal: '60.000',
            total: '71.400',
        );

        $threw = false;
        try {
            $this->service()->post($creditNote);
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Over-credit beyond invoiced quantity must throw');

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
        $poLine = $this->invoicedPoLine('5.0000', '10.000', '5.0000');
        $creditNote = $this->supplierCreditNote(
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
        $poLine = $this->invoicedPoLine('5.0000', '10.000', '5.0000');
        $creditNote = $this->supplierCreditNote(
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
}
