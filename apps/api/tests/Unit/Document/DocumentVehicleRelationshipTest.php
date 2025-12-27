<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for Document-Vehicle relationship.
 *
 * Verifies that documents can be associated with vehicles and that
 * the vehicle relationship is properly accessible.
 */
class DocumentVehicleRelationshipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function document_can_have_vehicle(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-TEST-001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'balance_due' => '0.00',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot()
            ->create();

        $document->refresh();

        $this->assertNotNull($document->vehicle_id);
        $this->assertEquals($context->vehicle_id, $document->vehicle_id);
        $this->assertInstanceOf(DocumentVehicleContext::class, $document->vehicleContext);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function document_vehicle_relationship_returns_snapshot(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-TEST-001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'balance_due' => '0.00',
        ]);

        $vehicleSnapshot = [
            'license_plate' => 'AB-123-CD',
            'brand' => 'Peugeot',
            'model' => '208',
            'year' => 2023,
            'vin' => 'VF3XXXXXXXX123456',
            'color' => 'blue',
            'fuel_type' => 'diesel',
        ];

        DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot($vehicleSnapshot)
            ->create();

        $document->refresh();
        $retrievedSnapshot = $document->vehicle;

        $this->assertIsArray($retrievedSnapshot);
        $this->assertArrayHasKey('license_plate', $retrievedSnapshot);
        $this->assertEquals('AB-123-CD', $retrievedSnapshot['license_plate']);
        $this->assertEquals('Peugeot', $retrievedSnapshot['brand']);
        $this->assertIsString($document->getVehicleDisplayString());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function document_without_vehicle_returns_null(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-TEST-002',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'balance_due' => '0.00',
        ]);

        // Do not create a DocumentVehicleContext

        $this->assertNull($document->vehicle_id);
        $this->assertNull($document->vehicle);
        $this->assertNull($document->vehicleContext);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function vehicle_can_be_assigned_to_document(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-TEST-003',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'balance_due' => '0.00',
        ]);

        $this->assertNull($document->vehicle_id);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot()
            ->create();

        $document->refresh();

        $this->assertEquals($context->vehicle_id, $document->vehicle_id);
        $this->assertNotNull($document->vehicle);
        $this->assertIsArray($document->vehicle);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function vehicle_can_be_removed_from_document(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-TEST-004',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'balance_due' => '0.00',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot()
            ->create();

        $document->refresh();
        $this->assertNotNull($document->vehicle_id);

        $context->delete();
        $document->refresh();

        $this->assertNull($document->vehicle_id);
        $this->assertNull($document->vehicle);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function different_document_types_can_have_vehicles(): void
    {
        $documentTypes = [
            DocumentType::Quote,
            DocumentType::SalesOrder,
            DocumentType::Invoice,
            DocumentType::DeliveryNote,
            DocumentType::CreditNote,
        ];

        $counter = 0;
        foreach ($documentTypes as $type) {
            $counter++;
            $document = Document::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->partner->id,
                'type' => $type,
                'status' => DocumentStatus::Draft,
                'document_number' => "DOC-TEST-{$counter}",
                'document_date' => now(),
                'currency' => 'EUR',
                'subtotal' => '0.00',
                'tax_amount' => '0.00',
                'total' => '0.00',
                'balance_due' => '0.00',
            ]);

            $context = DocumentVehicleContext::factory()
                ->for($document)
                ->withSnapshot()
                ->create();

            $document->refresh();

            $this->assertEquals($context->vehicle_id, $document->vehicle_id);
            $this->assertNotNull($document->vehicle);
            $this->assertIsArray($document->vehicle);
            $this->assertInstanceOf(DocumentVehicleContext::class, $document->vehicleContext);
        }
    }
}
