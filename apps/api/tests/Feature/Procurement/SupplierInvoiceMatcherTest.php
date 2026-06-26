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

        // Must NOT throw — warn allows price variance through
        $this->matcher->assertPostable($invoice, MatchEnforcement::Warn);
        $this->assertTrue(true); // explicit assertion that no exception was thrown
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
}
