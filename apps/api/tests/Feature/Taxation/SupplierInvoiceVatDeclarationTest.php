<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
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
use App\Modules\Taxation\Application\Services\VatReportGenerationService;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
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
     * A postable supplier invoice: 100 × 5.000 = 500.000 HT, 19% = 95.000 VAT,
     * fully received against its purchase order and accrued to GR/IR so
     * `SupplierInvoicePostingService::post()` clears cleanly.
     */
    private function postableSupplierInvoice(): Document
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
            'quantity' => '100.0000',
            'quantity_received' => '100.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => '500.000',
            'allocated_costs' => '0.0000',
            'accrual_unit_cost' => '5.000000',
        ]);

        app(\App\Modules\Accounting\Domain\Services\GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            '100.0000',
            '5.000',
            'TND',
        );

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'source_document_id' => $po->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-B19-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '500.000',
            'line_tax_amount' => '95.000',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => '95.000',
            'total' => '595.000',
            'match_status' => SupplierInvoiceMatchStatus::Unmatched,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'B19 supplier invoice line',
            'quantity' => '100.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'tax_rate' => '19.00',
            'tax_amount' => '95.000',
            'tax_recoverable' => true,
            'recoverable_tax_amount' => '95.000',
            'non_recoverable_tax_amount' => '0.000',
            'line_total' => '500.000',
            'allocated_costs' => '0.0000',
            'source_line_id' => $poLine->id,
            'price_match_basis' => '5.000000',
        ]);

        return $invoice->load('lines');
    }
}
