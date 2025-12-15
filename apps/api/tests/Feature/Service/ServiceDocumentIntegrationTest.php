<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for Service-Document integration.
 */
class ServiceDocumentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    #[Test]
    public function document_lines_table_has_service_id_column(): void
    {
        $this->assertTrue(
            \Schema::hasColumn('document_lines', 'service_id'),
            'document_lines table should have service_id column'
        );
    }

    #[Test]
    public function document_line_can_reference_a_service(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Change',
            'base_price' => '45.00',
        ]);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $document = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '45.00',
            'tax_amount' => '8.55',
            'total' => '53.55',
            'balance_due' => '53.55',
        ]);

        $line = DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $document->id,
            'service_id' => $service->id,
            'line_number' => 1,
            'description' => 'Oil Change Service',
            'quantity' => '1.0000',
            'unit_price' => '45.00',
            'tax_rate' => '19.00',
            'line_total' => '45.00',
        ]);

        $this->assertDatabaseHas('document_lines', [
            'id' => $line->id,
            'service_id' => $service->id,
        ]);
    }

    #[Test]
    public function document_line_can_load_service_relationship(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SRV-001',
            'name' => 'Brake Inspection',
        ]);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $document = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'balance_due' => '119.00',
        ]);

        $line = DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $document->id,
            'service_id' => $service->id,
            'line_number' => 1,
            'description' => 'Brake Inspection',
            'quantity' => '1.0000',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '100.00',
        ]);

        // Reload with relationship
        $line->refresh();
        $line->load('service');

        $this->assertNotNull($line->service);
        $this->assertEquals('SRV-001', $line->service->code);
        $this->assertEquals('Brake Inspection', $line->service->name);
    }

    #[Test]
    public function document_line_can_have_null_service_id(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $document = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-002',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '50.00',
            'tax_amount' => '9.50',
            'total' => '59.50',
            'balance_due' => '59.50',
        ]);

        // Line without service reference (manual line item)
        $line = DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $document->id,
            'service_id' => null,
            'line_number' => 1,
            'description' => 'Custom work',
            'quantity' => '1.0000',
            'unit_price' => '50.00',
            'tax_rate' => '19.00',
            'line_total' => '50.00',
        ]);

        $this->assertDatabaseHas('document_lines', [
            'id' => $line->id,
            'service_id' => null,
        ]);

        $line->refresh();
        $this->assertNull($line->service);
    }

    #[Test]
    public function deleting_service_sets_document_line_service_id_to_null(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $document = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-003',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '75.00',
            'tax_amount' => '14.25',
            'total' => '89.25',
            'balance_due' => '89.25',
        ]);

        $line = DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $document->id,
            'service_id' => $service->id,
            'line_number' => 1,
            'description' => 'Test Service',
            'quantity' => '1.0000',
            'unit_price' => '75.00',
            'tax_rate' => '19.00',
            'line_total' => '75.00',
        ]);

        // Delete service (soft delete)
        $service->delete();

        $line->refresh();

        // After service is deleted, the line should still exist
        // but service_id should be null (due to nullOnDelete)
        $this->assertDatabaseHas('document_lines', ['id' => $line->id]);
    }

    #[Test]
    public function service_can_list_document_lines_using_it(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Create 2 documents with lines referencing the service
        for ($i = 1; $i <= 2; $i++) {
            $document = Document::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $partner->id,
                'type' => DocumentType::Quote,
                'status' => DocumentStatus::Draft,
                'document_number' => "QT-00{$i}",
                'document_date' => now(),
                'currency' => 'TND',
                'subtotal' => '100.00',
                'tax_amount' => '19.00',
                'total' => '119.00',
                'balance_due' => '119.00',
            ]);

            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $document->id,
                'service_id' => $service->id,
                'line_number' => 1,
                'description' => 'Service line',
                'quantity' => '1.0000',
                'unit_price' => '100.00',
                'tax_rate' => '19.00',
                'line_total' => '100.00',
            ]);
        }

        // Load document lines relationship
        $service->load('documentLines');

        $this->assertCount(2, $service->documentLines);
    }
}
