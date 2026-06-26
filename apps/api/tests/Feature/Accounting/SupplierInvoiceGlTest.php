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
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\SupplierInvoicePostingService;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SupplierInvoiceGlTest — C3 fiscal core.
 *
 * On posting a supplier_invoice, in ONE locked transaction, post a balanced,
 * hash-chained GR-IR clearing entry (option 1, accrued-basis):
 *   Dr GoodsReceivedNotInvoiced (408)  = accrued HT  (Σ invoiced_qty × PO unit_price)
 *   Dr VatDeductible                   = Σ recoverable_tax_amount
 *   Dr PurchaseStampDuty               = stamp_duty_amount (timbre, never to VAT)
 *   Dr/Cr Inventory                    = balancing plug (price delta + non-recoverable VAT)
 *   Cr SupplierPayable (401)           = invoice.total (partner-tagged)
 *
 * 408 always zeroes (cleared at the accrued PO-cost basis B1 used).
 */
final class SupplierInvoiceGlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $supplier;

    private Account $grirAccount;

    private Account $vatDeductibleAccount;

    private Account $stampDutyAccount;

    private Account $payableAccount;

    private Account $inventoryAccount;

    private GeneralLedgerHashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'C3 SI GL Test Tenant',
            'slug' => 'c3-si-gl-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'C3 SI GL Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->grirAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $this->vatDeductibleAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatDeductible);
        $this->stampDutyAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchaseStampDuty);
        $this->payableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);
        $this->inventoryAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Inventory);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'C3 Test Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        // Deterministic policy: received / three-way / warn, 2% / 1.000 tolerance.
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
        ]);

        $this->hashService = app(GeneralLedgerHashService::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a confirmed PO with one line and accrue the B1 408 credit for the
     * received quantity (so the clearing entry has something to zero).
     *
     * @param  numeric-string  $qty
     * @param  numeric-string  $unitPrice
     * @return DocumentLine the PO line (source line referenced by invoice lines)
     */
    private function confirmedPoWithReceipt(string $qty, string $unitPrice): DocumentLine
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
            'document_number' => 'PO-C3-'.Str::upper(Str::random(6)),
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
            'description' => 'C3 PO line',
            'quantity' => $qty,
            'quantity_delivered' => '0.0000',
            'quantity_received' => $qty,
            'quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'line_total' => $extended,
            'allocated_costs' => '0.0000',
        ]);

        // Accrue 408 (B1) for the received quantity: Cr 408 / Dr Inventory.
        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            $qty,
            $unitPrice,
            'TND',
        );

        return $line;
    }

    /**
     * Create a Draft supplier invoice with a single line linked to $poLine.
     *
     * @param  array{qty: numeric-string, unit_price: numeric-string, recoverable_vat: numeric-string, non_recoverable_vat?: numeric-string}  $line
     * @param  numeric-string  $stampDuty
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $total
     */
    private function supplierInvoice(DocumentLine $poLine, array $line, string $stampDuty, string $subtotal, string $total): Document
    {
        $nonRecoverable = $line['non_recoverable_vat'] ?? '0.000';
        $lineTax = $line['recoverable_vat'];

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-C3-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $subtotal,
            'line_tax_amount' => $lineTax,
            'stamp_duty_amount' => $stampDuty,
            'tax_amount' => bcadd($lineTax, $stampDuty, 3),
            'total' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'C3 SI line',
            'quantity' => $line['qty'],
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => $line['unit_price'],
            'line_total' => bcmul($line['qty'], $line['unit_price'], 3),
            'allocated_costs' => '0.0000',
            'tax_amount' => bcadd($lineTax, $nonRecoverable, 3),
            'tax_recoverable' => true,
            'recoverable_tax_amount' => $lineTax,
            'non_recoverable_tax_amount' => $nonRecoverable,
            'source_line_id' => $poLine->id,
        ]);

        $invoice->load('lines');

        return $invoice;
    }

    private function service(): SupplierInvoicePostingService
    {
        return app(SupplierInvoicePostingService::class);
    }

    private function freshLine(DocumentLine $line): DocumentLine
    {
        return DocumentLine::findOrFail($line->id);
    }

    private function freshDoc(Document $doc): Document
    {
        return Document::findOrFail($doc->id);
    }

    private function clearingEntry(Document $invoice): JournalEntry
    {
        return JournalEntry::where('source_type', 'supplier_invoice')
            ->where('source_id', $invoice->id)
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

    /** Net 408 balance for the company = Σ credit − Σ debit on the GR-IR account. */
    private function net408(): string
    {
        $net = '0.000';
        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_lines.account_id', $this->grirAccount->id)
            ->select('journal_lines.debit', 'journal_lines.credit')
            ->get();
        foreach ($lines as $l) {
            $net = bcadd($net, bcsub($l->credit, $l->debit, 3), 3);
        }

        return $net;
    }

    // =========================================================================
    // 1. Matched invoice, all-recoverable VAT, with timbre — clean 4-leg entry.
    // =========================================================================

    public function test_matched_invoice_clears_408_with_vat_and_timbre_no_inventory_leg(): void
    {
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');
        $invoice = $this->supplierInvoice(
            $poLine,
            ['qty' => '5.0000', 'unit_price' => '10.000', 'recoverable_vat' => '9.500'],
            stampDuty: '0.600',
            subtotal: '50.000',
            total: '60.100',
        );

        $this->service()->post($invoice);

        $entry = $this->clearingEntry($invoice);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        // No Inventory leg (plug == 0): Dr408 + DrVAT + DrTimbre + Cr401 = 4 legs.
        $this->assertCount(4, $entry->lines);
        $this->assertNull($this->legOn($entry, $this->inventoryAccount), 'Matched case has no Inventory plug leg');

        // Dr 408 = accrued HT (PO basis) — account resolved BY PURPOSE.
        $dr408 = $this->legOn($entry, $this->grirAccount);
        $this->assertNotNull($dr408);
        $this->assertSame('50.000', $dr408->debit);
        $this->assertSame('0.000', $dr408->credit);
        $this->assertNull($dr408->partner_id);

        // Dr VatDeductible = recoverable VAT only.
        $drVat = $this->legOn($entry, $this->vatDeductibleAccount);
        $this->assertNotNull($drVat);
        $this->assertSame('9.500', $drVat->debit);
        $this->assertNull($drVat->partner_id);

        // Dr PurchaseStampDuty = timbre.
        $drTimbre = $this->legOn($entry, $this->stampDutyAccount);
        $this->assertNotNull($drTimbre);
        $this->assertSame('0.600', $drTimbre->debit);
        $this->assertNull($drTimbre->partner_id);

        // Cr 401 = invoice.total, partner-tagged.
        $cr401 = $this->legOn($entry, $this->payableAccount);
        $this->assertNotNull($cr401);
        $this->assertSame('0.000', $cr401->debit);
        $this->assertSame('60.100', $cr401->credit);
        $this->assertSame($this->supplier->id, $cr401->partner_id);

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));

        // quantity_invoiced incremented on the PO line.
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);

        // 408 fully cleared (accrual zeroed at accrued basis).
        $this->assertSame('0.000', $this->net408());

        // match_status persisted.
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $this->freshDoc($invoice)->match_status);
    }

    // =========================================================================
    // 2. Timbre never lands in VatDeductible (segregated accounts).
    // =========================================================================

    public function test_timbre_is_segregated_from_vat_deductible(): void
    {
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');
        $invoice = $this->supplierInvoice(
            $poLine,
            ['qty' => '5.0000', 'unit_price' => '10.000', 'recoverable_vat' => '9.500'],
            stampDuty: '0.600',
            subtotal: '50.000',
            total: '60.100',
        );

        $this->service()->post($invoice);
        $entry = $this->clearingEntry($invoice);

        $this->assertNotSame($this->vatDeductibleAccount->id, $this->stampDutyAccount->id);

        // VatDeductible carries ONLY the recoverable VAT, not the timbre.
        $drVat = $this->legOn($entry, $this->vatDeductibleAccount);
        $this->assertNotNull($drVat);
        $this->assertSame('9.500', $drVat->debit);

        // PurchaseStampDuty carries the timbre on its own account.
        $drTimbre = $this->legOn($entry, $this->stampDutyAccount);
        $this->assertNotNull($drTimbre);
        $this->assertSame('0.600', $drTimbre->debit);

        $this->assertBalanced($entry);
    }

    // =========================================================================
    // 3. Price variance under warn → posts, price delta routed to Inventory plug.
    // =========================================================================

    public function test_price_variance_under_warn_routes_delta_to_inventory_plug(): void
    {
        // PO @ 10.000, invoice @ 11.000 → 5.000 delta over 5 units (2% of 50 = 1.000 → variance).
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');
        $invoice = $this->supplierInvoice(
            $poLine,
            ['qty' => '5.0000', 'unit_price' => '11.000', 'recoverable_vat' => '10.450'],
            stampDuty: '0.000',
            subtotal: '55.000',
            total: '65.450',
        );

        $this->service()->post($invoice);
        $entry = $this->clearingEntry($invoice);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        // Dr 408 still at the PO (accrued) basis.
        $dr408 = $this->legOn($entry, $this->grirAccount);
        $this->assertNotNull($dr408);
        $this->assertSame('50.000', $dr408->debit);

        // Dr Inventory = the price delta plug.
        $drInv = $this->legOn($entry, $this->inventoryAccount);
        $this->assertNotNull($drInv, 'Price variance must produce an Inventory plug leg');
        $this->assertSame('5.000', $drInv->debit);
        $this->assertSame('0.000', $drInv->credit);

        // Dr VAT on the billed basis.
        $drVat = $this->legOn($entry, $this->vatDeductibleAccount);
        $this->assertNotNull($drVat);
        $this->assertSame('10.450', $drVat->debit);

        // Cr 401 = billed gross.
        $cr401 = $this->legOn($entry, $this->payableAccount);
        $this->assertNotNull($cr401);
        $this->assertSame('65.450', $cr401->credit);

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));

        // 408 still zeroes (cleared at accrued basis).
        $this->assertSame('0.000', $this->net408());
        $this->assertSame(SupplierInvoiceMatchStatus::PriceVariance, $this->freshDoc($invoice)->match_status);
    }

    // =========================================================================
    // 4. Idempotency: second post is a no-op (no duplicate, no double-increment).
    // =========================================================================

    public function test_idempotent_second_post_is_noop(): void
    {
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');
        $invoice = $this->supplierInvoice(
            $poLine,
            ['qty' => '5.0000', 'unit_price' => '10.000', 'recoverable_vat' => '9.500'],
            stampDuty: '0.600',
            subtotal: '50.000',
            total: '60.100',
        );

        $this->service()->post($invoice);
        $this->service()->post($this->freshDoc($invoice));

        $count = JournalEntry::where('source_type', 'supplier_invoice')
            ->where('source_id', $invoice->id)
            ->count();
        $this->assertSame(1, $count, 'Second post must not create a duplicate clearing entry');

        // quantity_invoiced incremented exactly once.
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame('0.000', $this->net408());
    }

    // =========================================================================
    // 5. Hard 408 invariant under warn: over-clear THROWS and rolls back.
    // =========================================================================

    public function test_hard_overclear_throws_under_warn_and_rolls_back(): void
    {
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');
        // Invoice 6 units against 5 received → quantity over-clear (hard violation).
        $invoice = $this->supplierInvoice(
            $poLine,
            ['qty' => '6.0000', 'unit_price' => '10.000', 'recoverable_vat' => '11.400'],
            stampDuty: '0.000',
            subtotal: '60.000',
            total: '71.400',
        );

        $threw = false;
        try {
            $this->service()->post($invoice);
        } catch (\DomainException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Over-clear must throw even under warn enforcement');

        // No GL entry, no increment, invoice still Draft (transaction rolled back).
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_invoice')->where('source_id', $invoice->id)->count());
        $this->assertSame('0.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($invoice)->status);
    }

    // =========================================================================
    // 6. Partial: first invoice clears part of 408; a later invoice clears the rest.
    // =========================================================================

    public function test_partial_then_remainder_fully_clears_408(): void
    {
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');

        // First invoice: 3 of 5 units.
        $invoice1 = $this->supplierInvoice(
            $poLine,
            ['qty' => '3.0000', 'unit_price' => '10.000', 'recoverable_vat' => '5.700'],
            stampDuty: '0.000',
            subtotal: '30.000',
            total: '35.700',
        );
        $this->service()->post($invoice1);

        $entry1 = $this->clearingEntry($invoice1);
        $dr408First = $this->legOn($entry1, $this->grirAccount);
        $this->assertNotNull($dr408First);
        $this->assertSame('30.000', $dr408First->debit);
        $this->assertBalanced($entry1);
        $this->assertSame('3.0000', $this->freshLine($poLine)->quantity_invoiced);
        // 408 partially cleared: 50 accrued − 30 cleared = 20 remaining.
        $this->assertSame('20.000', $this->net408());

        // Second invoice: remaining 2 units.
        $invoice2 = $this->supplierInvoice(
            $poLine,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            stampDuty: '0.000',
            subtotal: '20.000',
            total: '23.800',
        );
        $this->service()->post($invoice2);

        $entry2 = $this->clearingEntry($invoice2);
        $dr408Second = $this->legOn($entry2, $this->grirAccount);
        $this->assertNotNull($dr408Second);
        $this->assertSame('20.000', $dr408Second->debit);
        $this->assertBalanced($entry2);
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);
        // 408 fully cleared.
        $this->assertSame('0.000', $this->net408());
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }
}
