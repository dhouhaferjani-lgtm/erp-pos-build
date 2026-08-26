<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\CreateSupplierInvoiceService;
use App\Modules\Procurement\Application\SupplierCreditNotePostingService;
use App\Modules\Procurement\Application\SupplierInvoicePostingService;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\Enums\SupplierCreditNoteReason;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Taxation\Application\Services\VatReportGenerationService;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Exceptions\ReturnPeriodLockedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B-19 (P0, owner sheet OWNER-SHEET-2026-08-21-first-client-session.md,
 * "supplier-invoice deductible VAT is silently absent from the TN
 * declaration"): a supplier invoice's input VAT never reached the Tunisian
 * VAT declaration, because of a DOUBLE lock —
 *
 *   1. `SupplierInvoicePostingService::post()` never called
 *      `TaxCalculationService::snapshotTaxDetails()`, so a posted supplier
 *      invoice carried ZERO `document_tax_details` rows (verified on the
 *      local demo tenant 2026-08-26: 43 supplier invoices, 704.401 TND of
 *      VAT, 0 tax-detail rows); and
 *   2. `EloquentVatDataRepository::aggregateByRateAndDirection()` restricted
 *      the document arm to invoice / credit_note / expense, so even a
 *      snapshotted supplier invoice would have been ignored.
 *
 * Either lock alone hides the whole deductible-VAT population, so both are
 * pinned here, plus the declaration-level end-to-end (one sales invoice +
 * one supplier invoice must produce BOTH output and deductible VAT).
 *
 * SNAPSHOT POINT = `post()`, NOT create(). Every other arm of the
 * declaration snapshots on the state transition that makes the document
 * fiscally real, never at draft creation:
 *   - `InvoiceController::confirm()`      (Draft → Confirmed)
 *   - `CreditNoteController::confirm()`   (Draft → Confirmed)
 *   - `QuoteController::confirm()`        (Draft → Confirmed)
 *   - `SalesOrderService::confirm()`      (Draft → Confirmed)
 *   - `PurchaseOrderService::confirm()`   (Draft → Confirmed)
 *   - `DeliveryNoteService::confirm()`    (Draft → Confirmed)
 *   - `ReturnNoteService::confirmWithFiscalChain()`
 *   - `ExpenseService::post()`            (Draft → Posted)
 * The repository applies NO status filter, so "a document_tax_details row
 * exists" IS the system's proxy for "this document is fiscally recognised".
 * Snapshotting a supplier invoice at CREATION would therefore declare every
 * unposted draft's input VAT (31 drafts / 390.517 TND on the demo tenant
 * alone), booking a deduction against invoices that carry no journal entry
 * — a strictly worse defect than the one being fixed.
 */
final class SupplierInvoiceVatDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $supplier;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('countries')->insert([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'name' => 'B19 VAT Tenant',
            'slug' => 'b19-vat-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B19 VAT Company',
            'legal_name' => 'B19 VAT Company SARL',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'B19 Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'B19 Customer',
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
        ]);

        // The TN VAT rate table, shaped exactly as TunisiaTaxConfigurationSeeder
        // ships it: `applicable_document_types` lists the SALES fiscal
        // categories only, so a supplier invoice (fiscal_category = NonFiscal)
        // matches no configuration row and TaxCalculationService takes its
        // documented UNCONFIGURED branch, honouring the line's own rate.
        TaxConfiguration::create([
            'country_code' => 'TN',
            'tax_type' => TaxType::Percentage,
            'name' => 'TVA 19%',
            'code' => 'TVA_19',
            'percentage_rate' => '19.00',
            'applies_to' => TaxApplicationLevel::LineItems,
            'is_default' => true,
            'is_active' => true,
            'sequence_order' => 1,
            'applicable_document_types' => ['TAX_INVOICE', 'FISCAL_RECEIPT', 'CREDIT_NOTE', 'DELIVERY_NOTE'],
            'is_stamp_duty' => false,
            'is_recoverable' => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // Lock 1 — the writer
    // ---------------------------------------------------------------------

    public function test_posting_a_supplier_invoice_snapshots_its_input_vat(): void
    {
        $invoice = $this->postableSupplierInvoice();

        app(SupplierInvoicePostingService::class)->post($invoice);

        $details = DocumentTaxDetail::where('document_id', $invoice->id)->get();

        $this->assertCount(1, $details, 'A posted supplier invoice must carry exactly one input-VAT snapshot row.');
        $this->assertSame('19.00', (string) $details[0]->tax_rate);
        $this->assertSame('500.000', (string) $details[0]->tax_base);
        $this->assertSame('95.000', (string) $details[0]->tax_amount);
        $this->assertFalse((bool) $details[0]->is_stamp_duty);
    }

    public function test_a_draft_supplier_invoice_is_not_snapshotted(): void
    {
        $invoice = $this->postableSupplierInvoice();

        $this->assertSame(
            0,
            DocumentTaxDetail::where('document_id', $invoice->id)->count(),
            'An UNPOSTED supplier invoice carries no journal entry, so its input VAT must not be declarable.',
        );
    }

    public function test_reposting_does_not_duplicate_the_input_vat_snapshot(): void
    {
        $invoice = $this->postableSupplierInvoice();

        $service = app(SupplierInvoicePostingService::class);
        $service->post($invoice);
        $service->post(Document::findOrFail($invoice->id));

        $this->assertSame(1, DocumentTaxDetail::where('document_id', $invoice->id)->count());
    }

    // ---------------------------------------------------------------------
    // Lock 2 — the repository
    // ---------------------------------------------------------------------

    public function test_repository_aggregates_supplier_invoice_tax_details_as_deductible_input(): void
    {
        $invoice = $this->postableSupplierInvoice();
        app(SupplierInvoicePostingService::class)->post($invoice);

        $results = app(VatDataRepositoryInterface::class)->aggregateByRateAndDirection(
            $this->company->id,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        );

        $this->assertCount(1, $results);
        $this->assertSame('INPUT', $results[0]->direction);
        $this->assertSame('19.00', $results[0]->taxRate);
        $this->assertSame('500.000', $results[0]->baseAmount);
        $this->assertSame('95.000', $results[0]->vatAmount);
        $this->assertSame(1, $results[0]->documentCount);
        $this->assertTrue($results[0]->isRecoverable);
    }

    // ---------------------------------------------------------------------
    // Declaration level
    // ---------------------------------------------------------------------

    public function test_tn_declaration_carries_both_output_and_deductible_vat(): void
    {
        $this->confirmedSalesInvoice();

        $supplierInvoice = $this->postableSupplierInvoice();
        app(SupplierInvoicePostingService::class)->post($supplierInvoice);

        $summary = app(VatReportGenerationService::class)->generateSummary(
            $this->company->id,
            'TN',
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        );

        $this->assertSame('190.000', $summary->outputVat['total_vat']);
        $this->assertSame(
            '95.000',
            $summary->inputVat['total_vat'],
            'B-19: the supplier invoice deductible VAT must reach the declaration.',
        );

        /** @var array<string, string|int|float> $fields */
        $fields = $summary->declaration['fields'];
        $this->assertSame('190.000', $fields['total_output_vat']);
        $this->assertSame('95.000', $fields['total_deductible_vat']);
        $this->assertSame('1000.000', $fields['base_19']);
        $this->assertSame('95.000', $summary->netVat, 'Net VAT = 190.000 output - 95.000 deductible.');
    }

    // ---------------------------------------------------------------------
    // Fix round 1 — B-2 / F1 / F5: the snapshot must be the figure the GL posted
    // ---------------------------------------------------------------------

    /**
     * ORCHESTRATOR RULING (fix round 1): the declared input VAT must be derived
     * from the PERSISTED line tax amounts — the figures the GL actually booked —
     * never recomputed through TaxCalculationService.
     *
     * The gold case, taken from live data (demo tenant SI-2026-0008 /
     * SI-2026-0020): 10 x 12.601 = 126.010 HT, x 19% = 23.9419 exactly.
     *   - CreateSupplierInvoiceService rounds HALF-UP per line -> 23.942, and that
     *     is what SupplierInvoicePostingService debits to 4456.
     *   - TaxCalculationService accumulates at scale+1 and TRUNCATES once per rate
     *     bucket -> 23.941.
     * A declaration that cannot be tied to the 4456 movement is an unexplainable
     * reconciliation break at audit, so 23.942 is the only admissible answer.
     */
    public function test_snapshot_carries_the_vat_the_gl_posted_when_per_line_rounding_diverges(): void
    {
        $invoice = $this->postableSupplierInvoice(qty: '10.0000', unitPrice: '12.601');

        app(SupplierInvoicePostingService::class)->post($invoice);

        $details = DocumentTaxDetail::where('document_id', $invoice->id)->get();
        $this->assertCount(1, $details);
        $this->assertSame(
            '23.942',
            (string) $details[0]->tax_amount,
            'The snapshot must carry the half-up per-line figure the GL debited to 4456, not the engine truncation (23.941).',
        );
        $this->assertSame('126.010', (string) $details[0]->tax_base);

        // And it must tie to the ledger, not merely differ from the engine.
        $this->assertSame(
            '23.942',
            $this->postedDeductibleVat($invoice),
            'Declared input VAT must equal the amount debited to the VAT-deductible account.',
        );
    }

    // ---------------------------------------------------------------------
    // Fix round 1 — B-1 / F2: no back-dated post into a locked declaration
    // ---------------------------------------------------------------------

    public function test_posting_into_a_closed_vat_period_is_refused(): void
    {
        $invoice = $this->postableSupplierInvoice();
        $this->createVatPeriod(VatPeriodStatus::Closed);

        $this->expectException(ReturnPeriodLockedException::class);

        try {
            app(SupplierInvoicePostingService::class)->post($invoice);
        } finally {
            $this->assertSame(
                0,
                DocumentTaxDetail::where('document_id', $invoice->id)->count(),
                'A refused post must leave no deductible row behind.',
            );
        }
    }

    public function test_posting_into_a_filed_vat_period_is_refused(): void
    {
        $invoice = $this->postableSupplierInvoice();
        $this->createVatPeriod(VatPeriodStatus::Filed);

        $this->expectException(ReturnPeriodLockedException::class);

        app(SupplierInvoicePostingService::class)->post($invoice);
    }

    public function test_posting_into_an_open_period_is_permitted(): void
    {
        $invoice = $this->postableSupplierInvoice();
        $this->createVatPeriod(VatPeriodStatus::Open);

        app(SupplierInvoicePostingService::class)->post($invoice);

        $this->assertSame(1, DocumentTaxDetail::where('document_id', $invoice->id)->count());
    }

    public function test_posting_a_supplier_credit_note_into_a_closed_period_is_refused(): void
    {
        $invoice = $this->postableSupplierInvoice();
        app(SupplierInvoicePostingService::class)->post($invoice);
        $creditNote = $this->draftSupplierCreditNote($invoice);
        $this->createVatPeriod(VatPeriodStatus::Closed);

        $this->expectException(ReturnPeriodLockedException::class);

        app(SupplierCreditNotePostingService::class)->post($creditNote);
    }

    /**
     * N1 (fix round 2, both r2 gates). The idempotency no-op must win over the
     * period guard: a re-post writes nothing, so refusing it for a declaration
     * reason refuses a declaration-neutral call. `SupplierCreditNotePostingService`
     * already ordered it this way; the invoice arm did not.
     */
    public function test_reposting_after_the_period_closed_is_still_a_no_op(): void
    {
        $invoice = $this->postableSupplierInvoice();
        app(SupplierInvoicePostingService::class)->post($invoice);

        $this->createVatPeriod(VatPeriodStatus::Closed);

        // Must NOT throw.
        app(SupplierInvoicePostingService::class)->post(Document::findOrFail($invoice->id));

        $this->assertSame(1, DocumentTaxDetail::where('document_id', $invoice->id)->count());
    }

    /**
     * The population most exposed to N1: AR/AP opening documents are created
     * already-Posted with their own journal entry and are back-dated BY
     * CONSTRUCTION (`ArApOpeningService`), so they sit inside closed periods
     * almost by definition. Posting one must be the documented no-op.
     */
    public function test_posting_a_back_dated_ap_opening_document_is_a_no_op_not_a_refusal(): void
    {
        $opening = $this->historicalApOpeningDocument();
        $this->createVatPeriod(VatPeriodStatus::Filed);

        app(SupplierInvoicePostingService::class)->post($opening);

        $this->assertSame(
            0,
            DocumentTaxDetail::where('document_id', $opening->id)->count(),
            'A historical opening declares nothing — it was declared under the previous system.',
        );
    }

    // ---------------------------------------------------------------------
    // Fix round 1 — A / F3: supplier credit notes must REDUCE the deduction
    // ---------------------------------------------------------------------

    public function test_posting_a_supplier_credit_note_snapshots_its_input_vat_positive(): void
    {
        $invoice = $this->postableSupplierInvoice();
        app(SupplierInvoicePostingService::class)->post($invoice);

        $creditNote = $this->draftSupplierCreditNote($invoice);
        app(SupplierCreditNotePostingService::class)->post($creditNote);

        $details = DocumentTaxDetail::where('document_id', $creditNote->id)->get();
        $this->assertCount(1, $details);
        // Stored POSITIVE, exactly as a sales credit note is: a
        // document_tax_details row always reads "this much tax on this document"
        // and is never sign-overloaded. The repository negates at aggregation.
        $this->assertSame('50.000', (string) $details[0]->tax_base);
        $this->assertSame('9.500', (string) $details[0]->tax_amount);
    }

    public function test_repository_negates_supplier_credit_note_rows_on_the_input_side(): void
    {
        $invoice = $this->postableSupplierInvoice();
        app(SupplierInvoicePostingService::class)->post($invoice);
        $creditNote = $this->draftSupplierCreditNote($invoice);
        app(SupplierCreditNotePostingService::class)->post($creditNote);

        $results = app(VatDataRepositoryInterface::class)->aggregateByRateAndDirection(
            $this->company->id,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        );

        $this->assertCount(1, $results);
        $this->assertSame('INPUT', $results[0]->direction);
        $this->assertSame('450.000', $results[0]->baseAmount, '500.000 invoiced - 50.000 credited.');
        $this->assertSame('85.500', $results[0]->vatAmount, '95.000 deducted - 9.500 given back.');
        $this->assertSame(2, $results[0]->documentCount, 'Both documents are declared documents.');
    }

    public function test_tn_declaration_nets_a_supplier_credit_note_out_of_the_deduction(): void
    {
        $this->confirmedSalesInvoice();

        $invoice = $this->postableSupplierInvoice();
        app(SupplierInvoicePostingService::class)->post($invoice);
        $creditNote = $this->draftSupplierCreditNote($invoice);
        app(SupplierCreditNotePostingService::class)->post($creditNote);

        $summary = app(VatReportGenerationService::class)->generateSummary(
            $this->company->id,
            'TN',
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        );

        /** @var array<string, string|int|float> $fields */
        $fields = $summary->declaration['fields'];
        $this->assertSame('190.000', $fields['total_output_vat']);
        $this->assertSame(
            '85.500',
            $fields['total_deductible_vat'],
            'F3: without the credit-note arm the declaration would over-claim by 9.500.',
        );
        $this->assertSame('104.500', $summary->netVat, '190.000 - 85.500.');
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * A 1000.000 HT / 19% sales invoice, snapshotted through the canonical
     * confirm-time producer (the same two calls `InvoiceController::confirm()`
     * makes), so the OUTPUT arm of the declaration is real data.
     */
    private function confirmedSalesInvoice(): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-B19-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '1000.000',
            'line_tax_amount' => '190.000',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => '190.000',
            'total' => '1190.000',
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'B19 sales line',
            'quantity' => '10.0000',
            'unit_price' => '100.000',
            'tax_rate' => '19.00',
            'tax_amount' => '190.000',
            'line_total' => '1000.000',
            'allocated_costs' => '0.0000',
        ]);

        $invoice->load(['company', 'partner', 'lines']);
        $taxService = app(TaxCalculationService::class);
        $taxService->snapshotTaxDetails($invoice, $taxService->calculateDocumentTaxes($invoice));

        return $invoice;
    }

    /**
     * A postable supplier invoice, built through the REAL creation path
     * (`CreateSupplierInvoiceService::create()` — the same service
     * `SupplierInvoiceController::store()`, `InvoiceFirstOrchestrator` and
     * `SupplierInvoiceCommitter` all delegate to), fully received against its
     * purchase order and accrued to GR/IR so `post()` clears cleanly.
     *
     * Treasury gate F10: an earlier revision built this with `Document::create()`,
     * which made `test_a_draft_supplier_invoice_is_not_snapshotted` tautological —
     * it pinned the fixture, not the production creation path. Driving the real
     * service means a future change that snapshotted at creation WOULD fail it.
     *
     * Defaults: 100 x 5.000 = 500.000 HT, 19% = 95.000 VAT (exact at every scale).
     * Pass `qty`/`unitPrice` to reach a sub-millime case.
     */
    private function postableSupplierInvoice(string $qty = '100.0000', string $unitPrice = '5.000'): Document
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-B19-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
        ]);

        $poLine = DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'B19 purchase line',
            'quantity' => $qty,
            'quantity_received' => $qty,
            'quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'line_total' => bcmul($qty, $unitPrice, 3),
            'allocated_costs' => '0.0000',
            'accrual_unit_cost' => $unitPrice.'000',
        ]);

        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            $qty,
            $unitPrice,
            'TND',
        );

        return app(CreateSupplierInvoiceService::class)->create(
            [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => now()->toDateString(),
                'source_document_ids' => [$po->id],
                'lines' => [[
                    'source_line_id' => $poLine->id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'vat_rate' => '19.00',
                ]],
            ],
            $this->tenant->id,
            $this->company->id,
        );
    }

    /**
     * A draft supplier credit note against a POSTED supplier invoice, using the
     * PriceAdjustment reason so the post exercises the VAT/GL reversal without
     * dragging in the goods-return / stock-exit arm.
     *
     * 100 x 0.500 = 50.000 HT, 19% = 9.500 VAT — comfortably inside the cumulative
     * over-credit bound (the invoice's 500.000 subtotal).
     */
    private function draftSupplierCreditNote(Document $invoice): Document
    {
        /** @var DocumentLine $invoiceLine */
        $invoiceLine = $invoice->lines()->firstOrFail();

        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'source_document_id' => $invoice->id,
            'type' => DocumentType::SupplierCreditNote,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SCN-B19-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '50.000',
            'line_tax_amount' => '9.500',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => '9.500',
            'total' => '59.500',
            'supplier_credit_note_reason' => SupplierCreditNoteReason::PriceAdjustment,
        ]);

        DocumentLine::create([
            'document_id' => $creditNote->id,
            'line_number' => 1,
            'description' => 'B19 price adjustment',
            'quantity' => '100.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '0.500',
            'tax_rate' => '19.00',
            'tax_amount' => '9.500',
            'tax_recoverable' => true,
            'recoverable_tax_amount' => '9.500',
            'non_recoverable_tax_amount' => '0.000',
            'line_total' => '50.000',
            'allocated_costs' => '0.0000',
            'source_line_id' => $invoiceLine->source_line_id,
        ]);

        return $creditNote->load('lines');
    }

    /**
     * An AR/AP opening document exactly as `ArApOpeningService` mints one:
     * type `supplier_invoice`, already Posted, `is_historical = true`,
     * `subtotal == total`, zero tax, NO lines, and its own opening journal entry.
     */
    private function historicalApOpeningDocument(): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Posted,
            'document_number' => 'HIST-SINV-'.Str::upper(Str::random(6)),
            'document_date' => now()->startOfMonth()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '1200.000',
            'discount_amount' => '0.000',
            'tax_amount' => '0.000',
            'total' => '1200.000',
            'balance_due' => '1200.000',
            'is_historical' => true,
        ]);

        DB::table('journal_entries')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'OB-'.Str::upper(Str::random(8)),
            'entry_date' => $document->document_date->toDateString(),
            'description' => 'AP opening',
            'source_type' => 'supplier_invoice',
            'source_id' => $document->id,
            'is_historical' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $document->load('lines');
    }

    private function createVatPeriod(VatPeriodStatus $status): VatPeriod
    {
        return VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'MONTHLY',
            'label' => now()->format('F Y'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => $status,
            'closed_at' => $status === VatPeriodStatus::Open ? null : now(),
        ]);
    }

    /**
     * The amount actually debited to the VAT-deductible account by the supplier
     * invoice's GR/IR clearing entry — the ledger figure the declaration must tie to.
     */
    private function postedDeductibleVat(Document $invoice): string
    {
        $vatAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatDeductible);

        /** @var JournalEntry $entry */
        $entry = JournalEntry::where('source_type', 'supplier_invoice')
            ->where('source_id', $invoice->id)
            ->firstOrFail()
            ->load('lines');

        $leg = $entry->lines->firstWhere('account_id', $vatAccount->id);
        $this->assertNotNull($leg, 'The clearing entry must carry a VAT-deductible leg.');

        return (string) $leg->debit;
    }
}
