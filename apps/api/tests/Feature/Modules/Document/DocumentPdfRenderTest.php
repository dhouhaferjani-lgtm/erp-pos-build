<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Verifies that the PDF line_items component renders $line->description (the stored
 * designation, which may be a user override) as the primary item name — never the
 * live product->name from the Product model.
 *
 * Also verifies that $line->product_code and $line->notes are rendered correctly.
 */
final class DocumentPdfRenderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('features.documents.line_designation_override.enabled', true);

        $this->tenant = Tenant::create([
            'name' => 'PDF Render Test Tenant',
            'slug' => 'pdf-render-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    public function test_pdf_line_items_render_stored_description_not_live_product_name(): void
    {
        $document = $this->buildInvoiceWithOverriddenLine(
            productName: 'Live Product Name From DB',
            lineDescription: 'Custom Brake Name',
            productCode: 'BRK-001',
            notes: '2.5h × 60/hr by John',
        );

        $html = $this->renderLineItemsComponent($document);

        // Primary text must be the stored description
        $this->assertStringContainsString('Custom Brake Name', $html);

        // Product code must appear in brackets
        $this->assertStringContainsString('[BRK-001]', $html);

        // Notes must appear in item-description element
        $this->assertStringContainsString('2.5h × 60/hr by John', $html);

        // The live product name must NOT appear (override wins)
        $this->assertStringNotContainsString('Live Product Name From DB', $html);
    }

    public function test_pdf_line_items_render_without_notes_when_notes_is_null(): void
    {
        $document = $this->buildInvoiceWithOverriddenLine(
            productName: 'Original Product',
            lineDescription: 'Override Description',
            productCode: 'SKU-999',
            notes: null,
        );

        $html = $this->renderLineItemsComponent($document);

        $this->assertStringContainsString('Override Description', $html);
        $this->assertStringContainsString('[SKU-999]', $html);
        $this->assertStringNotContainsString('item-description', $html);
        $this->assertStringNotContainsString('Original Product', $html);
    }

    public function test_pdf_line_items_render_without_sku_when_product_code_is_null(): void
    {
        $document = $this->buildInvoiceWithOverriddenLine(
            productName: 'Some Product',
            lineDescription: 'My Description',
            productCode: null,
            notes: 'Some notes',
        );

        $html = $this->renderLineItemsComponent($document);

        $this->assertStringContainsString('My Description', $html);
        $this->assertStringContainsString('Some notes', $html);
        $this->assertStringNotContainsString('[', $html);
    }

    /**
     * Build a posted invoice with a single line where:
     * - The product has a given name in the Product table
     * - The line's stored description is deliberately different (override scenario)
     */
    private function buildInvoiceWithOverriddenLine(
        string $productName,
        string $lineDescription,
        ?string $productCode,
        ?string $notes,
    ): Document {
        $suffix = random_int(10000, 99999);

        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => "PDF Test Company {$suffix}",
            'legal_name' => "PDF Test Company {$suffix} SAS",
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'date_format' => 'D MMM YYYY',
            'primary_color' => '#2563eb',
            'fiscal_year_start_month' => 1,
            'invoice_prefix' => 'INV-',
            'invoice_next_number' => 1,
            'quote_prefix' => 'QUO-',
            'quote_next_number' => 1,
            'sales_order_prefix' => 'SO-',
            'sales_order_next_number' => 1,
            'purchase_order_prefix' => 'PO-',
            'purchase_order_next_number' => 1,
            'delivery_note_prefix' => 'DN-',
            'delivery_note_next_number' => 1,
            'receipt_prefix' => 'REC-',
            'receipt_next_number' => 1,
        ]);

        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'PDF Test Partner',
            'type' => PartnerType::Customer,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => $productName,
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => "INV-{$suffix}",
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
            'subtotal' => '150.000',
            'tax_amount' => '30.000',
            'total' => '180.000',
            'balance_due' => '180.000',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'product_id' => $product->id,
            'product_code' => $productCode,
            'line_number' => 1,
            'description' => $lineDescription,
            'notes' => $notes,
            'designation_default_snapshot' => $productName,
            'quantity' => '1.000',
            'unit_price' => '150.000',
            'tax_rate' => '20.000',
            'line_total' => '150.000',
            'quantity_delivered' => '0.000',
            'quantity_received' => '0.000',
            'allocated_costs' => '0.000',
        ]);

        return $document->fresh(['company', 'partner', 'lines', 'lines.product']) ?? $document;
    }

    /**
     * Render only the line_items blade component and return the HTML string.
     */
    private function renderLineItemsComponent(Document $document): string
    {
        return view('documents.components.line_items', [
            'lines' => $document->lines,
            'showTax' => true,
            'formatNumber' => fn (string|float|null $n, int $d = 2): string => number_format((float) ($n ?? 0), $d),
            'formatMoney' => fn (string|float|null $a): string => number_format((float) ($a ?? 0), 2).' EUR',
        ])->render();
    }
}
