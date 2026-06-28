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
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C1 — SupplierInvoice DocumentType + SupplierInvoiceMatchStatus + quantity_invoiced column
 *
 * Exercises the foundational data layer only. No matcher, no GL posting (C2/C3).
 */
final class SupplierInvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Procurement Test Tenant',
            'slug' => 'procurement-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Procurement Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Supplier',
            'type' => PartnerType::Supplier,
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. DocumentType case, prefix, and label
    // -------------------------------------------------------------------------

    public function test_supplier_invoice_document_type_has_correct_prefix_and_label(): void
    {
        $type = DocumentType::SupplierInvoice;

        $this->assertSame('supplier_invoice', $type->value);
        $this->assertSame('SI', $type->getPrefix());
        $this->assertSame('Supplier Invoice', $type->label());
    }

    // -------------------------------------------------------------------------
    // 2. Supplier invoice persists and reloads via DocumentStatus (not match_status)
    // -------------------------------------------------------------------------

    public function test_supplier_invoice_document_persists_with_draft_status(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-2026-0001',
            'document_date' => '2026-06-26',
            'currency' => 'TND',
            'match_status' => SupplierInvoiceMatchStatus::Unmatched,
        ]);

        $fresh = Document::findOrFail($document->id);

        $this->assertSame(DocumentType::SupplierInvoice, $fresh->type);
        $this->assertSame(DocumentStatus::Draft, $fresh->status);
    }

    // -------------------------------------------------------------------------
    // 3. match_status round-trips through enum cast
    // -------------------------------------------------------------------------

    public function test_match_status_round_trips_through_cast(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-2026-0002',
            'document_date' => '2026-06-26',
            'currency' => 'TND',
            'match_status' => SupplierInvoiceMatchStatus::Unmatched,
        ]);

        $fresh = Document::findOrFail($document->id);

        $this->assertSame(SupplierInvoiceMatchStatus::Unmatched, $fresh->match_status);
        $this->assertSame('unmatched', $fresh->match_status->value);

        // Also verify null stays null for non-supplier documents
        $otherDoc = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'PO-2026-0001',
            'document_date' => '2026-06-26',
            'currency' => 'TND',
        ]);

        $freshOther = Document::findOrFail($otherDoc->id);
        $this->assertNull($freshOther->match_status);
    }

    // -------------------------------------------------------------------------
    // 4. FiscalCategory is NonFiscal for supplier_invoice
    // -------------------------------------------------------------------------

    public function test_fiscal_category_from_document_type_is_non_fiscal(): void
    {
        $this->assertSame(
            FiscalCategory::NonFiscal,
            FiscalCategory::fromDocumentType(DocumentType::SupplierInvoice),
        );
    }

    // -------------------------------------------------------------------------
    // 5. canTransitionToPaid returns true for supplier_invoice
    // -------------------------------------------------------------------------

    public function test_can_transition_to_paid_is_true_for_supplier_invoice(): void
    {
        $this->assertTrue(DocumentType::SupplierInvoice->canTransitionToPaid());
    }

    // -------------------------------------------------------------------------
    // 6. affectsReceivable and receivableDirection remain on defaults
    // -------------------------------------------------------------------------

    public function test_supplier_invoice_does_not_affect_receivable(): void
    {
        $this->assertFalse(DocumentType::SupplierInvoice->affectsReceivable());
        $this->assertSame(0, DocumentType::SupplierInvoice->receivableDirection());
    }

    // -------------------------------------------------------------------------
    // 7. quantity_invoiced column defaults to '0.0000' and round-trips
    // -------------------------------------------------------------------------

    public function test_quantity_invoiced_defaults_and_round_trips(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-2026-0003',
            'document_date' => '2026-06-26',
            'currency' => 'TND',
            'match_status' => SupplierInvoiceMatchStatus::Unmatched,
        ]);

        // Create line — quantity_invoiced should default to '0.0000'
        $line = DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Paracetamol 500mg x100',
            'quantity' => '10.0000',
            'unit_price' => '5.000',
            'line_total' => '50.000',
        ]);

        $freshLine = DocumentLine::findOrFail($line->id);
        $this->assertSame('0.0000', $freshLine->quantity_invoiced);

        // Set a value and reload
        $freshLine->quantity_invoiced = '3.0000';
        $freshLine->save();

        $reloaded = DocumentLine::findOrFail($line->id);
        $this->assertSame('3.0000', $reloaded->quantity_invoiced);
    }
}
