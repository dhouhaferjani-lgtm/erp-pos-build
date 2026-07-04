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
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
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

    private Account $ppvExpenseAccount;

    private Account $ppvIncomeAccount;

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
        $this->ppvExpenseAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchasePriceVarianceExpense);
        $this->ppvIncomeAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchasePriceVarianceIncome);

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
     * When $landedUnitCost is given, the PO line carries that landed cost AND the
     * 408 accrual is raised at the landed basis (mirroring B1 = landed_unit_cost ??
     * unit_price), so the clearing must also use the landed basis to zero 408.
     *
     * @param  numeric-string  $qty
     * @param  numeric-string  $unitPrice
     * @param  numeric-string|null  $landedUnitCost
     * @return DocumentLine the PO line (source line referenced by invoice lines)
     */
    private function confirmedPoWithReceipt(string $qty, string $unitPrice, ?string $landedUnitCost = null): DocumentLine
    {
        $extended = bcmul($qty, $unitPrice, 3);
        /** @var numeric-string $accrualCost */
        $accrualCost = $landedUnitCost ?? $unitPrice;

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
            'landed_unit_cost' => $landedUnitCost,
            'line_total' => $extended,
            'allocated_costs' => '0.0000',
        ]);

        // Accrue 408 (B1) for the received quantity at the landed basis: Cr 408 / Dr Inventory.
        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            $qty,
            $accrualCost,
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

    public function test_explicit_bonus_supplier_invoice_line_increments_free_counter_and_clears_408(): void
    {
        $poLine = $this->confirmedPoWithReceipt('20.0000', '5.000');
        $poLine->free_quantity = '1.0000';
        $poLine->free_quantity_received = '1.0000';
        $poLine->free_quantity_invoiced = '0.0000';
        $poLine->save();

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-BONUS-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'line_tax_amount' => '19.000',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Paid invoice line',
            'quantity' => '20.0000',
            'unit_price' => '5.000',
            'line_total' => '100.000',
            'tax_amount' => '19.000',
            'tax_recoverable' => true,
            'recoverable_tax_amount' => '19.000',
            'non_recoverable_tax_amount' => '0.000',
            'source_line_id' => $poLine->id,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 2,
            'description' => 'Remise en nature',
            'quantity' => '1.0000',
            'unit_price' => '5.000',
            'discount_percent' => '100.00',
            'line_total' => '0.000',
            'tax_amount' => '0.000',
            'tax_recoverable' => true,
            'recoverable_tax_amount' => '0.000',
            'non_recoverable_tax_amount' => '0.000',
            'source_line_id' => $poLine->id,
            'is_bonus_line' => true,
        ]);

        $invoice->load('lines');

        $this->service()->post($invoice);

        $freshPoLine = $this->freshLine($poLine);
        $freshInvoice = $this->freshDoc($invoice);

        $this->assertSame('20.0000', (string) $freshPoLine->quantity_invoiced);
        $this->assertSame('1.0000', (string) $freshPoLine->free_quantity_invoiced);
        $this->assertSame(DocumentStatus::Posted, $freshInvoice->status);
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $freshInvoice->match_status);
        $this->assertSame('0.000', $this->net408());
    }

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
    // 3. Price variance under warn → posts, price delta routed to PPV.
    // =========================================================================

    public function test_price_variance_under_warn_routes_delta_to_ppv_expense(): void
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

        $drPpv = $this->legOn($entry, $this->ppvExpenseAccount);
        $this->assertNotNull($drPpv, 'Unfavorable price variance must produce a PPV expense leg');
        $this->assertSame('5.000', $drPpv->debit);
        $this->assertSame('0.000', $drPpv->credit);
        $this->assertNull($this->legOn($entry, $this->inventoryAccount));

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

    // =========================================================================
    // 7. FIX 1 (BLOCKER): 408 cleared at the LANDED basis B1 accrued, not unit_price.
    // =========================================================================

    public function test_clears_408_at_landed_cost_basis_not_unit_price(): void
    {
        // PO billed at 10.000/unit; landed cost 10.500/unit (freight capitalized).
        // B1 accrued 408 at landed: 5 × 10.500 = 52.500.
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000', landedUnitCost: '10.500');
        $this->assertSame('52.500', $this->net408(), 'precondition: 408 accrued at landed basis');

        // Supplier bills at the PO price 10.000 (no price variance). billedHT = 50.000.
        $invoice = $this->supplierInvoice(
            $poLine,
            ['qty' => '5.0000', 'unit_price' => '10.000', 'recoverable_vat' => '9.500'],
            stampDuty: '0.000',
            subtotal: '50.000',
            total: '59.500',
        );

        $this->service()->post($invoice);
        $entry = $this->clearingEntry($invoice);

        // Dr 408 at the LANDED accrued basis = 52.500 (NOT unit_price 50.000).
        $dr408 = $this->legOn($entry, $this->grirAccount);
        $this->assertNotNull($dr408);
        $this->assertSame('52.500', $dr408->debit);

        // 408 fully zeroes — the regression: with unit_price basis it would leave 2.500.
        $this->assertSame('0.000', $this->net408());

        $ppvIncome = $this->legOn($entry, $this->ppvIncomeAccount);
        $this->assertNotNull($ppvIncome);
        $this->assertSame('0.000', $ppvIncome->debit);
        $this->assertSame('2.500', $ppvIncome->credit);
        $this->assertNull($this->legOn($entry, $this->inventoryAccount));

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $this->freshDoc($invoice)->match_status);
    }

    // =========================================================================
    // 8. FIX 3: non-recoverable VAT is capitalized into the Inventory plug.
    // =========================================================================

    public function test_non_recoverable_vat_capitalized_into_inventory_plug(): void
    {
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');

        // Invoice @ 11.000 (price delta 5.000) + recoverable 9.500 + non-recoverable 1.000.
        $invoice = $this->supplierInvoice(
            $poLine,
            ['qty' => '5.0000', 'unit_price' => '11.000', 'recoverable_vat' => '9.500', 'non_recoverable_vat' => '1.000'],
            stampDuty: '0.000',
            subtotal: '55.000',
            total: '65.500',
        );

        $this->service()->post($invoice);
        $entry = $this->clearingEntry($invoice);

        // VatDeductible carries ONLY recoverable VAT.
        $drVat = $this->legOn($entry, $this->vatDeductibleAccount);
        $this->assertNotNull($drVat);
        $this->assertSame('9.500', $drVat->debit);

        $drPpv = $this->legOn($entry, $this->ppvExpenseAccount);
        $this->assertNotNull($drPpv);
        $this->assertSame('5.000', $drPpv->debit);
        $this->assertSame('0.000', $drPpv->credit);

        // Inventory plug = non-recoverable VAT only; price delta routes to PPV.
        $drInv = $this->legOn($entry, $this->inventoryAccount);
        $this->assertNotNull($drInv);
        $this->assertSame('1.000', $drInv->debit);
        $this->assertSame('0.000', $drInv->credit);

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
        $this->assertSame('0.000', $this->net408());
    }

    // =========================================================================
    // 9. FIX 4: invoice price below PO price → NEGATIVE plug (Cr Inventory).
    // =========================================================================

    public function test_negative_plug_posts_credit_inventory_leg(): void
    {
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');

        // Invoice @ 9.000 → billedHT 45.000, recoverable VAT 8.550, total 53.550.
        $invoice = $this->supplierInvoice(
            $poLine,
            ['qty' => '5.0000', 'unit_price' => '9.000', 'recoverable_vat' => '8.550'],
            stampDuty: '0.000',
            subtotal: '45.000',
            total: '53.550',
        );

        $this->service()->post($invoice);
        $entry = $this->clearingEntry($invoice);

        // Dr 408 still at the accrued basis (50.000).
        $dr408 = $this->legOn($entry, $this->grirAccount);
        $this->assertNotNull($dr408);
        $this->assertSame('50.000', $dr408->debit);

        $ppvIncome = $this->legOn($entry, $this->ppvIncomeAccount);
        $this->assertNotNull($ppvIncome);
        $this->assertSame('0.000', $ppvIncome->debit);
        $this->assertSame('5.000', $ppvIncome->credit);
        $this->assertNull($this->legOn($entry, $this->inventoryAccount));

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
        // 408 still zeroes at the accrued basis.
        $this->assertSame('0.000', $this->net408());
    }

    // =========================================================================
    // 10. FIX 5a: multi-line invoice sharing one source_line_id aggregates.
    // =========================================================================

    public function test_multi_line_invoice_sharing_one_po_line_aggregates(): void
    {
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');

        // Two invoice lines, both linked to the same PO line: qty 2 + qty 3 = 5.
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
            'subtotal' => '50.000',
            'line_tax_amount' => '9.500',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => '9.500',
            'total' => '59.500',
        ]);
        foreach ([['n' => 1, 'q' => '2.0000', 'v' => '3.800'], ['n' => 2, 'q' => '3.0000', 'v' => '5.700']] as $l) {
            DocumentLine::create([
                'document_id' => $invoice->id,
                'line_number' => $l['n'],
                'description' => 'C3 SI multi line',
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
        $invoice->load('lines');

        $this->service()->post($invoice);
        $entry = $this->clearingEntry($invoice);

        // quantity_invoiced incremented by the SUM of both lines.
        $this->assertSame('5.0000', $this->freshLine($poLine)->quantity_invoiced);

        // 408 cleared for the aggregate (5 × 10.000 = 50.000) and zeroes.
        $dr408 = $this->legOn($entry, $this->grirAccount);
        $this->assertNotNull($dr408);
        $this->assertSame('50.000', $dr408->debit);
        $this->assertSame('0.000', $this->net408());

        $this->assertBalanced($entry);
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 11. FIX 5b: fail-loud balance invariant — inconsistent total throws + rolls back.
    // =========================================================================

    public function test_inconsistent_total_throws_balance_invariant_and_rolls_back(): void
    {
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');

        // billedHT 50 + recVAT 9.5 + timbre 0 = 59.500, but total is mis-stated as 60.000.
        $invoice = $this->supplierInvoice(
            $poLine,
            ['qty' => '5.0000', 'unit_price' => '10.000', 'recoverable_vat' => '9.500'],
            stampDuty: '0.000',
            subtotal: '50.000',
            total: '60.000',
        );

        $threw = false;
        try {
            $this->service()->post($invoice);
        } catch (\DomainException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Inconsistent total must fail loudly');

        // Rolled back: no JE, no increment, still Draft.
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_invoice')->where('source_id', $invoice->id)->count());
        $this->assertSame('0.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($invoice)->status);
    }

    // =========================================================================
    // 12. FIX 2: write-boundary over-clear guard rejects + rolls back cleanly.
    // =========================================================================

    public function test_write_boundary_overclear_rejected_and_rolls_back(): void
    {
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000');

        // First invoice clears 4 of 5 (commits): quantity_invoiced → 4.
        $invoice1 = $this->supplierInvoice(
            $poLine,
            ['qty' => '4.0000', 'unit_price' => '10.000', 'recoverable_vat' => '7.600'],
            stampDuty: '0.000',
            subtotal: '40.000',
            total: '47.600',
        );
        $this->service()->post($invoice1);
        $this->assertSame('4.0000', $this->freshLine($poLine)->quantity_invoiced);

        // Second invoice tries 2 more → 4 + 2 = 6 would exceed received 5. Must reject.
        $invoice2 = $this->supplierInvoice(
            $poLine,
            ['qty' => '2.0000', 'unit_price' => '10.000', 'recoverable_vat' => '3.800'],
            stampDuty: '0.000',
            subtotal: '20.000',
            total: '23.800',
        );

        $threw = false;
        try {
            $this->service()->post($invoice2);
        } catch (\DomainException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Over-clear at the write boundary must throw');

        // Rolled back: no partial increment (stays at 4), no orphan JE for invoice2.
        $this->assertSame('4.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_invoice')->where('source_id', $invoice2->id)->count());
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($invoice2)->status);
    }

    // =========================================================================
    // 13. B3 BLOCKER: landed_unit_cost changed AFTER receipt → post() throws
    //     divergence DomainException; no JE, status unchanged.
    // =========================================================================

    public function test_landed_cost_changed_after_receipt_post_throws_divergence_exception(): void
    {
        // Receipt accrued 408 at landed_unit_cost=10.500 (B1 = 5 × 10.500 = 52.500).
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000', landedUnitCost: '10.500');
        $this->assertSame('52.500', $this->net408(), 'precondition: 408 accrued at 52.500');

        // Explicitly record the receipt-time accrual basis on the PO line (simulates
        // what GoodsReceiptService::processReceiptLines() will set after the B3 fix).
        $poLine->accrual_unit_cost = '10.500';
        $poLine->save();

        // Simulate LandedCostService reallocating costs AFTER receipt — changes the
        // mutable landed_unit_cost while the JE (52.500 Cr-408) is already immutable.
        $poLine->landed_unit_cost = '12.000';
        $poLine->save();

        $invoice = $this->supplierInvoice(
            $this->freshLine($poLine),
            ['qty' => '5.0000', 'unit_price' => '10.000', 'recoverable_vat' => '9.500'],
            stampDuty: '0.000',
            subtotal: '50.000',
            total: '59.500',
        );

        $threw = false;
        $exceptionMessage = '';
        try {
            $this->service()->post($invoice);
        } catch (\DomainException $e) {
            $threw = true;
            $exceptionMessage = $e->getMessage();
        }

        $this->assertTrue($threw, 'Post must throw when landed_unit_cost diverges from accrual basis');
        $this->assertStringContainsStringIgnoringCase('accrual', $exceptionMessage,
            'Exception message must mention "accrual" to be diagnosable');

        // Rolled back: no JE, no quantity_invoiced increment, invoice still Draft.
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_invoice')
            ->where('source_id', $invoice->id)->count());
        $this->assertSame('0.0000', $this->freshLine($poLine)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, $this->freshDoc($invoice)->status);

        // 408 is still at the receipt-accrued credit (not partially cleared).
        $this->assertSame('52.500', $this->net408(), '408 must not be touched when post throws');
    }

    // =========================================================================
    // 14. B3 HAPPY PATH: no landed-cost change → post balanced, 408 zeroes.
    //     Explicitly verifies the guard is a no-op when basis is unchanged.
    // =========================================================================

    public function test_accrual_unit_cost_unchanged_no_divergence_posts_balanced(): void
    {
        // PO line with landed_unit_cost=10.500 AND accrual_unit_cost=10.500 (same).
        $poLine = $this->confirmedPoWithReceipt('5.0000', '10.000', landedUnitCost: '10.500');
        $poLine->accrual_unit_cost = '10.500';
        $poLine->save();

        // No change to landed_unit_cost after receipt — bases match, guard is a no-op.
        $invoice = $this->supplierInvoice(
            $this->freshLine($poLine),
            ['qty' => '5.0000', 'unit_price' => '10.000', 'recoverable_vat' => '9.500'],
            stampDuty: '0.000',
            subtotal: '50.000',
            total: '59.500',
        );

        $this->service()->post($invoice);

        $entry = $this->clearingEntry($invoice);
        // 408 cleared at the landed basis: 5 × 10.500 = 52.500.
        $dr408 = $this->legOn($entry, $this->grirAccount);
        $this->assertNotNull($dr408);
        $this->assertSame('52.500', $dr408->debit);
        $this->assertBalanced($entry);
        $this->assertSame('0.000', $this->net408(), '408 must zero when no divergence');
    }

    public function test_wave4_override_clears_408_at_accrual_unit_cost_before_landed_unit_cost(): void
    {
        // Receipt accrued 408 at override basis 5.200, while legacy landed_unit_cost
        // still carries the original PO basis until Wave 5 moves clearing to receipt lines.
        $poLine = $this->confirmedPoWithReceipt('100.0000', '5.000', landedUnitCost: '5.200');
        $poLine->landed_unit_cost = '5.000';
        $poLine->accrual_unit_cost = '5.200';
        $poLine->save();
        $receipt = GoodsReceipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'purchase_order_id' => $poLine->document_id,
            'receipt_number' => 'GRN-W4-'.Str::upper(Str::random(6)),
            'status' => GoodsReceiptStatus::Posted,
            'received_at' => now(),
        ]);
        GoodsReceiptLine::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'goods_receipt_id' => $receipt->id,
            'po_line_id' => $poLine->id,
            'product_id' => Str::uuid()->toString(),
            'received_qty' => '100.0000',
            'free_qty' => '0.0000',
            'received_unit_price' => '5.200',
            'landed_unit_cost' => '5.200000',
            'accrual_unit_cost' => '5.200000',
            'effective_unit_cost' => '5.200000',
            'quantity_invoiced' => '0.0000',
        ]);

        $invoice = $this->supplierInvoice(
            $this->freshLine($poLine),
            ['qty' => '100.0000', 'unit_price' => '5.200', 'recoverable_vat' => '98.800'],
            stampDuty: '0.000',
            subtotal: '520.000',
            total: '618.800',
        );

        $this->service()->post($invoice);

        $entry = $this->clearingEntry($invoice);
        $dr408 = $this->legOn($entry, $this->grirAccount);

        $this->assertNotNull($dr408);
        $this->assertSame('520.000', $dr408->debit);
        $this->assertSame('0.000', $this->net408());
        $this->assertBalanced($entry);
    }
}
