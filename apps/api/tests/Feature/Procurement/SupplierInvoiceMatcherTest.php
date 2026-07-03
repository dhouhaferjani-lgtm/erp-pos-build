<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
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
use App\Modules\Procurement\Application\SupplierInvoiceMatcher;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C2 — SupplierInvoiceMatcher: 3-way matching with hard 408 quantity invariant
 *
 * Key invariant under test: the HARD quantity over-clear invariant must NEVER be
 * bypassable via match_enforcement=warn. Warn governs ONLY price variance.
 *
 * Covers:
 *   1. Happy path (10 received, 0 invoiced, invoice 6 @ PO price) → matched; matchableQty math
 *   2. Over-clear quantity → quantity_variance; assertPostable throws under BOTH warn AND block
 *   3. Unreceived (received=0, invoice 5) → exception; assertPostable throws under warn AND block
 *   4. Price within dual-threshold tolerance → matched; assertPostable ok under warn and block
 *   5. Price beyond tolerance → price_variance; assertPostable(warn) ok; assertPostable(block) throws
 *   6. Missing source_line_id → exception; assertPostable throws under any enforcement
 *   7. (FIX 1) Multi-line invoice referencing same PO line — aggregate qty checked, over-clear blocked
 *   8. (FIX 2) source_line_id pointing to non-PO document type → exception
 *   9. (FIX 2) source_line_id pointing to PO in different company → exception
 *  10. (FIX 2) source_line_id pointing to non-existent UUID → exception
 *  11. (FIX 3) Invoice with zero lines → exception; assertPostable throws
 *  12. (FIX 4) Price tolerance AND vs OR distinguishing test
 */
final class SupplierInvoiceMatcherTest extends TestCase
{
    use RefreshDatabase;

    private SupplierInvoiceMatcher $matcher;

    private Tenant $tenant;

    private Company $company;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = app(SupplierInvoiceMatcher::class);

        $this->tenant = Tenant::create([
            'name' => 'Procurement Matcher Tenant',
            'slug' => 'matcher-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Matcher Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Supplier SA',
            'type' => PartnerType::Supplier,
        ]);

        // Seed a persisted policy with known tolerances so tests are deterministic.
        // 2% / max 1.000 TND — matches the vertical default values intentionally.
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received->value,
            'match_mode' => MatchMode::ThreeWay->value,
            'match_enforcement' => MatchEnforcement::Warn->value,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a minimal PO document + one line and return both.
     *
     * @param  array{quantity?: string, quantity_received?: string, quantity_invoiced?: string, unit_price?: string}  $lineAttrs
     * @return array{po: Document, poLine: DocumentLine}
     */
    private function seedPo(array $lineAttrs = []): array
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-TEST-'.uniqid(),
            'document_date' => '2026-06-01',
            'currency' => 'TND',
        ]);

        $poLine = DocumentLine::create(array_merge([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Paracetamol 500mg x100',
            'quantity' => '10.0000',
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => '50.000',
        ], $lineAttrs));

        return ['po' => $po, 'poLine' => $poLine];
    }

    /**
     * Create a supplier invoice with one line that references a PO line.
     *
     * @param  array{quantity?: string, unit_price?: string, source_line_id?: string|null}  $lineAttrs
     */
    private function seedInvoice(array $lineAttrs = []): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-TEST-'.uniqid(),
            'document_date' => '2026-06-15',
            'currency' => 'TND',
            'match_status' => SupplierInvoiceMatchStatus::Unmatched,
        ]);

        DocumentLine::create(array_merge([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Paracetamol 500mg x100',
            'quantity' => '6.0000',
            'unit_price' => '5.000',
            'line_total' => '30.000',
        ], $lineAttrs));

        // Reload with lines eager-loaded
        return $invoice->fresh(['lines']);
    }

    // -------------------------------------------------------------------------
    // Test 1 — happy path: matched + matchableQty math
    // -------------------------------------------------------------------------

    public function test_matched_when_qty_and_price_are_within_tolerance(): void
    {
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '6.0000',
            'unit_price' => '5.000',
            'source_line_id' => $poLine->id,
        ]);

        // matchableQty = 10.0000 − 0.0000 = 10.0000
        $this->assertSame('10.0000', $this->matcher->matchableQty($poLine));

        $status = $this->matcher->match($invoice);
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $status);

        // No throw under either enforcement mode
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Block);

        // Verify matchable AFTER the invoice: 10 − 6 = 4.0000 (manual assertion)
        $poLine->quantity_invoiced = '6.0000';
        $poLine->save();
        $freshPoLine = DocumentLine::findOrFail($poLine->id);
        $this->assertSame('4.0000', $this->matcher->matchableQty($freshPoLine));
    }

    public function test_explicit_bonus_invoice_line_is_skipped_from_paid_qty_and_price_checks(): void
    {
        ['poLine' => $poLine] = $this->seedPo([
            'quantity' => '20.0000',
            'quantity_received' => '20.0000',
            'quantity_invoiced' => '0.0000',
            'free_quantity' => '1.0000',
            'free_quantity_received' => '1.0000',
            'free_quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '20.0000',
            'unit_price' => '5.000',
            'line_total' => '100.000',
            'source_line_id' => $poLine->id,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 2,
            'description' => 'Remise en nature - Paracetamol 500mg x100',
            'quantity' => '1.0000',
            'unit_price' => '5.000',
            'discount_percent' => '100.00',
            'line_total' => '0.000',
            'source_line_id' => $poLine->id,
            'is_bonus_line' => true,
        ]);
        $invoice->load('lines');

        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $this->matcher->match($invoice));
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
    }

    // -------------------------------------------------------------------------
    // Test 2 — over-clear: quantity_variance is NEVER bypassable by warn
    // -------------------------------------------------------------------------

    public function test_quantity_variance_throws_under_warn_and_block_regardless_of_enforcement(): void
    {
        // Received 10, already invoiced 6 → matchable = 4; trying to invoice 6 more (6 > 4)
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '6.0000',
            'unit_price' => '5.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '6.0000',
            'unit_price' => '5.000',
            'source_line_id' => $poLine->id,
        ]);

        $this->assertSame(SupplierInvoiceMatchStatus::QuantityVariance, $this->matcher->match($invoice));

        // CRITICAL: warn MUST NOT bypass the hard quantity invariant
        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
    }

    public function test_quantity_variance_also_throws_under_block(): void
    {
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '6.0000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '6.0000',
            'source_line_id' => $poLine->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Block);
    }

    // -------------------------------------------------------------------------
    // Test 3 — unreceived: exception is never bypassable
    // -------------------------------------------------------------------------

    public function test_unreceived_invoice_is_exception_and_throws_under_warn(): void
    {
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '5.0000',
            'unit_price' => '5.000',
            'source_line_id' => $poLine->id,
        ]);

        $this->assertSame(SupplierInvoiceMatchStatus::Exception, $this->matcher->match($invoice));

        // Warn must NOT allow posting when exception is present (hard invariant)
        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
    }

    public function test_unreceived_invoice_also_throws_under_block(): void
    {
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '5.0000',
            'source_line_id' => $poLine->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Block);
    }

    // -------------------------------------------------------------------------
    // Test 4 — price within dual-threshold tolerance → matched
    // -------------------------------------------------------------------------

    public function test_price_within_tolerance_is_matched_and_postable_under_both_enforcements(): void
    {
        // PO price: 10.000, invoice price: 10.100
        // Variance per unit: 0.100; extended variance: 0.100 × 6 = 0.600
        // PO extended: 10.000 × 6 = 60.000
        // Percent threshold: 60.000 × 0.02 = 1.200
        // Max amount: 1.000
        // withinPercent: 0.600 ≤ 1.200 → true
        // withinMaxAmount: 0.600 ≤ 1.000 → true → matched
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '6.0000',
            'unit_price' => '10.100',
            'source_line_id' => $poLine->id,
        ]);

        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $this->matcher->match($invoice));

        // Both enforcement modes allow posting when within tolerance
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Block);
    }

    // -------------------------------------------------------------------------
    // Test 5 — price beyond tolerance → price_variance; advisory split
    // -------------------------------------------------------------------------

    public function test_price_beyond_tolerance_is_price_variance(): void
    {
        // PO price: 10.000, invoice price: 11.500
        // Variance per unit: 1.500; extended variance: 1.500 × 6 = 9.000
        // PO extended: 10.000 × 6 = 60.000
        // Percent threshold: 60.000 × 0.02 = 1.200
        // withinPercent: 9.000 ≤ 1.200 → false → price_variance
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '6.0000',
            'unit_price' => '11.500',
            'source_line_id' => $poLine->id,
        ]);

        $this->assertSame(SupplierInvoiceMatchStatus::PriceVariance, $this->matcher->match($invoice));
    }

    public function test_price_variance_is_postable_under_warn(): void
    {
        // price_variance is advisory — warn allows posting
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '6.0000',
            'unit_price' => '11.500',
            'source_line_id' => $poLine->id,
        ]);

        // Confirm we actually have a price variance before testing the leniency
        $this->assertSame(SupplierInvoiceMatchStatus::PriceVariance, $this->matcher->match($invoice));

        // Must NOT throw — warn allows price variance through
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
    }

    public function test_price_variance_blocks_posting_under_block(): void
    {
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '6.0000',
            'unit_price' => '11.500',
            'source_line_id' => $poLine->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Block);
    }

    // -------------------------------------------------------------------------
    // Test 6 — missing source_line_id → exception → always throws
    // -------------------------------------------------------------------------

    public function test_missing_source_line_is_exception_and_always_throws(): void
    {
        $invoice = $this->seedInvoice([
            'quantity' => '5.0000',
            'unit_price' => '5.000',
            'source_line_id' => null,
        ]);

        $this->assertSame(SupplierInvoiceMatchStatus::Exception, $this->matcher->match($invoice));

        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
    }

    public function test_missing_source_line_also_throws_under_block(): void
    {
        $invoice = $this->seedInvoice([
            'source_line_id' => null,
        ]);

        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Block);
    }

    // -------------------------------------------------------------------------
    // Test 7 (FIX 1) — multi-line invoice: aggregate qty per PO line
    // -------------------------------------------------------------------------

    /**
     * BLOCKER-1: two invoice lines on the same invoice referencing the SAME PO line
     * must have their quantities SUMMED against matchable. Each line individually
     * is within bounds (3 ≤ 4), but their aggregate (6 > 4) is an over-clear.
     * match() must return QuantityVariance and assertPostable(Warn) must throw —
     * the warn enforcement does NOT bypass the hard quantity invariant.
     */
    public function test_multi_line_invoice_same_po_line_aggregates_qty_and_rejects_over_clear_under_warn(): void
    {
        // PO line: received=10, already invoiced=6 → matchable=4
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '6.0000',
            'unit_price' => '5.000',
        ]);

        // Supplier invoice with TWO lines, both source_line_id = same PO line.
        // Each qty = 3.0000 — individually each would pass (3 ≤ 4).
        // Aggregate: 3 + 3 = 6 > 4 → must be QuantityVariance.
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-MULTI-'.uniqid(),
            'document_date' => '2026-06-15',
            'currency' => 'TND',
            'match_status' => SupplierInvoiceMatchStatus::Unmatched,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Paracetamol line A',
            'quantity' => '3.0000',
            'unit_price' => '5.000',
            'line_total' => '15.000',
            'source_line_id' => $poLine->id,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 2,
            'description' => 'Paracetamol line B',
            'quantity' => '3.0000',
            'unit_price' => '5.000',
            'line_total' => '15.000',
            'source_line_id' => $poLine->id,
        ]);

        $invoice = $invoice->fresh(['lines']);

        $this->assertSame(
            SupplierInvoiceMatchStatus::QuantityVariance,
            $this->matcher->match($invoice),
        );

        // CRITICAL: warn MUST NOT bypass the hard quantity invariant even in the multi-line case
        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
    }

    // -------------------------------------------------------------------------
    // Test 8 (FIX 2a) — source_line_id pointing to non-PO document type → exception
    // -------------------------------------------------------------------------

    public function test_source_line_id_pointing_to_non_purchase_order_line_is_exception(): void
    {
        // Create a SalesOrder with a line — using that line as source_line_id is invalid.
        $salesOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SalesOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-TEST-'.uniqid(),
            'document_date' => '2026-06-01',
            'currency' => 'TND',
        ]);

        $salesLine = DocumentLine::create([
            'document_id' => $salesOrder->id,
            'line_number' => 1,
            'description' => 'Item on a sales order',
            'quantity' => '10.0000',
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => '50.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '5.0000',
            'unit_price' => '5.000',
            'source_line_id' => $salesLine->id,
        ]);

        $this->assertSame(SupplierInvoiceMatchStatus::Exception, $this->matcher->match($invoice));

        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
    }

    // -------------------------------------------------------------------------
    // Test 9 (FIX 2b) — source_line_id pointing to PO line of different company → exception
    // -------------------------------------------------------------------------

    public function test_source_line_id_pointing_to_po_line_from_different_company_is_exception(): void
    {
        // Create a second company in the same tenant
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        // Create a PO in the OTHER company
        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Supplier',
            'type' => PartnerType::Supplier,
        ]);

        $otherPo = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-OTHER-'.uniqid(),
            'document_date' => '2026-06-01',
            'currency' => 'TND',
        ]);

        $otherPoLine = DocumentLine::create([
            'document_id' => $otherPo->id,
            'line_number' => 1,
            'description' => 'Item in other company PO',
            'quantity' => '10.0000',
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => '50.000',
        ]);

        // Invoice for $this->company referencing a PO line from $otherCompany
        $invoice = $this->seedInvoice([
            'quantity' => '5.0000',
            'unit_price' => '5.000',
            'source_line_id' => $otherPoLine->id,
        ]);

        $this->assertSame(SupplierInvoiceMatchStatus::Exception, $this->matcher->match($invoice));

        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
    }

    // -------------------------------------------------------------------------
    // Test 10 (FIX 2c) — source_line_id pointing to non-existent UUID → exception
    // -------------------------------------------------------------------------

    public function test_source_line_id_pointing_to_nonexistent_uuid_is_exception(): void
    {
        // Create a valid PO line, point the invoice line at it, then delete the PO line
        // to simulate a dangling source_line_id (e.g. PO line deleted after invoice was drafted).
        // SQLite FK constraints prevent inserting a non-existent UUID directly, so we
        // temporarily disable FKs for the delete of the referenced row.
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '5.0000',
            'unit_price' => '5.000',
            'source_line_id' => $poLine->id,
        ]);

        // Disable FK enforcement to allow deleting the referenced line
        DB::statement('PRAGMA foreign_keys = OFF');
        $poLine->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        // $invoice already has lines loaded in memory (source_line_id still references the now-gone ID).
        // DocumentLine::find($poLine->id) will return null → Exception.
        $this->assertSame(SupplierInvoiceMatchStatus::Exception, $this->matcher->match($invoice));

        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
    }

    // -------------------------------------------------------------------------
    // Test 11 (FIX 3) — invoice with zero lines → exception
    // -------------------------------------------------------------------------

    public function test_invoice_with_no_lines_is_exception_and_always_throws(): void
    {
        // Create a supplier invoice but add no lines.
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-EMPTY-'.uniqid(),
            'document_date' => '2026-06-15',
            'currency' => 'TND',
            'match_status' => SupplierInvoiceMatchStatus::Unmatched,
        ]);

        // Eager-load lines — will be empty collection
        $invoice = $invoice->fresh(['lines']);

        $this->assertSame(SupplierInvoiceMatchStatus::Exception, $this->matcher->match($invoice));

        $this->expectException(\DomainException::class);
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
    }

    // -------------------------------------------------------------------------
    // Test 12 (FIX 4) — AND vs OR price tolerance distinguishing test
    // -------------------------------------------------------------------------

    /**
     * The dual-threshold uses AND logic: Matched iff variance ≤ percentage_threshold
     * AND variance ≤ max_amount.  This test picks a variance that falls BETWEEN the
     * two thresholds, proving AND (not OR / max) is in effect.
     *
     * Policy: percent=2.00 (2%), max_amount=1.000 TND
     * PO price: 10.000, qty: 6.0000
     * Invoice price: 10.183
     *
     * Arithmetic (bcmath at declared scales):
     *   unit_diff        = |10.183 − 10.000| = 0.183
     *   extended_variance = 0.183 × 6.0000  = 1.098   (scale 3)
     *   po_extended       = 10.000 × 6.0000  = 60.000
     *   percent_threshold = 60.000 × 0.020000 = 1.200
     *
     *   withinPercentage: 1.098 ≤ 1.200 → true   ← passes the percentage gate
     *   withinMaxAmount:  1.098 ≤ 1.000 → false  ← fails the max-amount gate
     *
     *   AND → price_variance   (both must pass; one failing is enough to reject)
     *   OR  → matched          (one passing would have been enough)
     *
     * If the implementation used OR/max instead of AND, this test would wrongly
     * return Matched because withinPercentage is true.
     */
    public function test_price_variance_and_semantics_both_thresholds_must_pass(): void
    {
        ['poLine' => $poLine] = $this->seedPo([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
        ]);

        $invoice = $this->seedInvoice([
            'quantity' => '6.0000',
            'unit_price' => '10.183',
            'source_line_id' => $poLine->id,
        ]);

        // Extended variance 1.098 is ≤ percentage threshold 1.200 (passes OR)
        // but > max_amount 1.000 (fails AND) → price_variance under AND, matched under OR.
        $this->assertSame(
            SupplierInvoiceMatchStatus::PriceVariance,
            $this->matcher->match($invoice),
        );
    }
}
