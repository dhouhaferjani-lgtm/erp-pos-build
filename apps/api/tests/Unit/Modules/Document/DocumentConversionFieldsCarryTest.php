<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T10: All doc-to-doc converters must carry notes and
 * designation_default_snapshot from source lines to target lines.
 */
final class DocumentConversionFieldsCarryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private Product $serviceProduct;

    private Product $physicalProduct;

    private DocumentConverterRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->serviceProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        $this->physicalProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        $this->registry = app(DocumentConverterRegistry::class);

        // Bind the company context so converters that gate on
        // CompanyContext::requireCompanyId() (currently
        // InvoiceToCreditNoteConverter -> CreditNoteService) resolve the
        // same tenant + company this test seeded. In production the
        // CompanyContextMiddleware sets these from the request; the unit
        // test bypasses HTTP, so we set them directly on the singleton.
        $context = app(CompanyContext::class);
        $context->setCompanyId($this->company->id);
    }

    // -------------------------------------------------------------------------
    // QuoteToSalesOrderConverter
    // -------------------------------------------------------------------------

    public function test_quote_to_sales_order_carries_notes_and_designation_snapshot(): void
    {
        $quote = $this->createDocument(DocumentType::Quote, DocumentStatus::Confirmed, [
            [
                'product_id' => $this->serviceProduct->id,
                'description' => 'Overridden Name',
                'notes' => 'Additional detail',
                'designation_default_snapshot' => 'Original Product Name',
            ],
        ]);

        $order = $this->registry->convert($quote, DocumentType::SalesOrder);

        $line = $order->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);
        $this->assertSame('Overridden Name', $line->description);
        $this->assertSame('Additional detail', $line->notes);
        $this->assertSame('Original Product Name', $line->designation_default_snapshot);
    }

    public function test_quote_to_sales_order_carries_null_notes_and_null_snapshot(): void
    {
        $quote = $this->createDocument(DocumentType::Quote, DocumentStatus::Confirmed, [
            [
                'product_id' => $this->serviceProduct->id,
                'description' => 'Basic Description',
                'notes' => null,
                'designation_default_snapshot' => null,
            ],
        ]);

        $order = $this->registry->convert($quote, DocumentType::SalesOrder);

        $line = $order->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);
        $this->assertNull($line->notes);
        $this->assertNull($line->designation_default_snapshot);
    }

    // -------------------------------------------------------------------------
    // SalesOrderToInvoiceConverter (services-only, uses trait copyLines)
    // -------------------------------------------------------------------------

    public function test_sales_order_to_invoice_carries_notes_and_designation_snapshot(): void
    {
        $order = $this->createDocument(DocumentType::SalesOrder, DocumentStatus::Confirmed, [
            [
                'product_id' => $this->serviceProduct->id,
                'description' => 'Overridden Name',
                'notes' => 'Some note',
                'designation_default_snapshot' => 'Canonical Product',
            ],
        ]);

        $invoice = $this->registry->convert($order, DocumentType::Invoice);

        $line = $invoice->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);
        $this->assertSame('Overridden Name', $line->description);
        $this->assertSame('Some note', $line->notes);
        $this->assertSame('Canonical Product', $line->designation_default_snapshot);
    }

    // -------------------------------------------------------------------------
    // SalesOrderToDeliveryNoteConverter (physical products)
    // -------------------------------------------------------------------------

    public function test_sales_order_to_delivery_note_carries_notes_and_designation_snapshot(): void
    {
        $order = $this->createDocument(DocumentType::SalesOrder, DocumentStatus::Confirmed, [
            [
                'product_id' => $this->physicalProduct->id,
                'description' => 'Overridden Part Name',
                'notes' => 'Handle with care',
                'designation_default_snapshot' => 'Brake Pad XL',
            ],
        ]);

        $delivery = $this->registry->convert($order, DocumentType::DeliveryNote);

        $line = $delivery->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);
        $this->assertSame('Overridden Part Name', $line->description);
        $this->assertSame('Handle with care', $line->notes);
        $this->assertSame('Brake Pad XL', $line->designation_default_snapshot);
    }

    // -------------------------------------------------------------------------
    // DeliveryNoteToInvoiceConverter
    // -------------------------------------------------------------------------

    public function test_delivery_note_to_invoice_carries_notes_and_designation_snapshot(): void
    {
        // Create a confirmed delivery note with overridden fields
        $delivery = $this->createDocument(DocumentType::DeliveryNote, DocumentStatus::Confirmed, [
            [
                'product_id' => $this->physicalProduct->id,
                'description' => 'Overridden DN Name',
                'notes' => 'DN note text',
                'designation_default_snapshot' => 'Physical Part Original',
            ],
        ]);

        $invoice = $this->registry->convert($delivery, DocumentType::Invoice, [
            'delivery_note_ids' => [$delivery->id],
        ]);

        $line = $invoice->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);
        $this->assertSame('Overridden DN Name', $line->description);
        $this->assertSame('DN note text', $line->notes);
        $this->assertSame('Physical Part Original', $line->designation_default_snapshot);
    }

    // -------------------------------------------------------------------------
    // InvoiceToCreditNoteConverter (line-based)
    // -------------------------------------------------------------------------

    public function test_invoice_to_credit_note_carries_notes_and_designation_snapshot(): void
    {
        // Create a posted invoice (required for credit notes)
        $invoice = $this->createDocument(DocumentType::Invoice, DocumentStatus::Posted, [
            [
                'product_id' => $this->serviceProduct->id,
                'description' => 'Overridden Invoice Name',
                'notes' => 'Invoice note',
                'designation_default_snapshot' => 'Original Service',
            ],
        ]);

        // Set required fiscal fields for posted invoice
        $invoice->update([
            'fiscal_hash' => hash('sha256', 'test'),
            'chain_sequence' => 1,
        ]);

        $line = $invoice->lines->first();
        $this->assertNotNull($line);

        $creditNote = $this->registry->convert($invoice, DocumentType::CreditNote, [
            'reason' => CreditNoteReason::RETURN->value,
            'lines' => [
                ['line_id' => $line->id, 'quantity' => '1.000'],
            ],
        ]);

        $cnLine = $creditNote->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $cnLine);
        $this->assertSame('Overridden Invoice Name', $cnLine->description);
        $this->assertSame('Invoice note', $cnLine->notes);
        $this->assertSame('Original Service', $cnLine->designation_default_snapshot);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a document with lines, all having the three designation fields set.
     *
     * @param  array<int, array{product_id: string|null, description: string, notes: string|null, designation_default_snapshot: string|null}>  $lines
     */
    private function createDocument(
        DocumentType $type,
        DocumentStatus $status,
        array $lines
    ): Document {
        $document = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => $type,
            'status' => $status,
            'document_number' => strtoupper($type->value).'-'.time().'-'.rand(1000, 9999),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'balance_due' => '119.000',
        ]);

        foreach ($lines as $index => $lineData) {
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $document->id,
                'line_number' => $index + 1,
                'product_id' => $lineData['product_id'],
                'description' => $lineData['description'],
                'quantity' => '1.000',
                'unit_price' => '100.000',
                'tax_rate' => '19.000',
                'line_total' => '100.000',
                'notes' => $lineData['notes'],
                'designation_default_snapshot' => $lineData['designation_default_snapshot'],
            ]);
        }

        return $document->fresh(['lines']) ?? $document;
    }
}
