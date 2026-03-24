<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentEntityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);
    }

    public function test_document_has_uuid_primary_key(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $this->assertIsString($document->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $document->id
        );
    }

    public function test_document_belongs_to_tenant(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $this->assertEquals($this->tenant->id, $document->tenant_id);
        $this->assertEquals($this->tenant->id, $document->tenant->id);
    }

    public function test_document_belongs_to_partner(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $this->assertEquals($this->customer->id, $document->partner_id);
        $this->assertEquals($this->customer->id, $document->partner->id);
        $this->assertEquals('John Doe', $document->partner->name);
    }

    public function test_document_has_lines(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Labor - Oil Change',
            'quantity' => '1.00',
            'unit_price' => '50.00',
            'tax_rate' => '20.00',
            'line_total' => '50.00',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 2,
            'description' => 'Oil Filter',
            'quantity' => '1.00',
            'unit_price' => '25.00',
            'tax_rate' => '20.00',
            'line_total' => '25.00',
        ]);

        $this->assertCount(2, $document->lines);
        $this->assertEquals('Labor - Oil Change', $document->lines->first()->description);
    }

    public function test_document_calculates_totals(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        $this->assertEquals('100.000', $document->subtotal);
        $this->assertEquals('20.000', $document->tax_amount);
        $this->assertEquals('120.000', $document->total);
    }

    public function test_document_type_enum_values(): void
    {
        $this->assertEquals('quote', DocumentType::Quote->value);
        $this->assertEquals('sales_order', DocumentType::SalesOrder->value);
        $this->assertEquals('invoice', DocumentType::Invoice->value);
        $this->assertEquals('credit_note', DocumentType::CreditNote->value);
        $this->assertEquals('delivery_note', DocumentType::DeliveryNote->value);
    }

    public function test_document_status_enum_values(): void
    {
        $this->assertEquals('draft', DocumentStatus::Draft->value);
        $this->assertEquals('confirmed', DocumentStatus::Confirmed->value);
        $this->assertEquals('posted', DocumentStatus::Posted->value);
        $this->assertEquals('cancelled', DocumentStatus::Cancelled->value);
    }

    public function test_document_uses_soft_deletes(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $documentId = $document->id;
        $document->delete();

        $this->assertNull(Document::find($documentId));
        $this->assertNotNull(Document::withTrashed()->find($documentId));
    }

    public function test_document_scope_for_tenant(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        $otherPartner = Partner::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Jane Smith',
            'type' => PartnerType::Customer,
        ]);

        Document::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $documents = Document::forTenant($this->tenant->id)->get();

        $this->assertCount(1, $documents);
        $this->assertEquals($this->tenant->id, $documents->first()->tenant_id);
    }

    public function test_document_scope_by_type(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $quotes = Document::forTenant($this->tenant->id)->ofType(DocumentType::Quote)->get();
        $invoices = Document::forTenant($this->tenant->id)->ofType(DocumentType::Invoice)->get();

        $this->assertCount(1, $quotes);
        $this->assertCount(1, $invoices);
        $this->assertEquals('QT-2025-0001', $quotes->first()->document_number);
        $this->assertEquals('INV-2025-0001', $invoices->first()->document_number);
    }

    public function test_document_scope_by_status(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $drafts = Document::forTenant($this->tenant->id)->inStatus(DocumentStatus::Draft)->get();
        $posted = Document::forTenant($this->tenant->id)->inStatus(DocumentStatus::Posted)->get();

        $this->assertCount(1, $drafts);
        $this->assertCount(1, $posted);
    }

    public function test_document_is_draft_helper(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $this->assertTrue($document->isDraft());
        $this->assertFalse($document->isPosted());
        $this->assertFalse($document->isCancelled());
    }

    public function test_document_is_posted_helper(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $this->assertFalse($document->isDraft());
        $this->assertTrue($document->isPosted());
        $this->assertFalse($document->isCancelled());
    }

    public function test_document_line_belongs_to_document(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $line = DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Service Item',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);

        $this->assertEquals($document->id, $line->document_id);
        $this->assertEquals($document->id, $line->document->id);
    }
}
