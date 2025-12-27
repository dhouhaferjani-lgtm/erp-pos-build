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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit tests for DocumentVehicleContext model.
 *
 * Verifies the linking table that decouples Vehicle from Document,
 * allowing AutoERP to remain universal for all industries.
 *
 * After P0 remediation: Tests snapshot-based vehicle context
 * instead of direct Vehicle model relationship.
 */
class DocumentVehicleContextTest extends TestCase
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
    public function document_can_have_vehicle_context_with_snapshot(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot([
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Camry',
                'year' => 2022,
            ])
            ->withMileage(50000)
            ->create([
                'context_data' => [
                    'service_type' => 'oil_change',
                ],
            ]);

        $this->assertInstanceOf(DocumentVehicleContext::class, $context);
        $this->assertEquals($document->id, $context->document_id);
        $this->assertNotNull($context->vehicle_id);
        $this->assertIsArray($context->vehicle_snapshot);
        $this->assertEquals('ABC-123', $context->vehicle_snapshot['license_plate']);
        $this->assertEquals('Toyota', $context->vehicle_snapshot['brand']);
        $this->assertEquals(50000, $context->mileage_at_service);
        $this->assertIsArray($context->context_data);
        $this->assertEquals('oil_change', $context->context_data['service_type']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function document_vehicle_context_provides_snapshot_accessor(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot([
                'license_plate' => 'ABC-123',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'year' => 2021,
            ])
            ->create();

        $snapshot = $context->getVehicleSnapshot();

        $this->assertIsArray($snapshot);
        $this->assertEquals('ABC-123', $snapshot['license_plate']);
        $this->assertEquals('Toyota', $snapshot['brand']);
        $this->assertEquals('Corolla', $snapshot['model']);
        $this->assertEquals(2021, $snapshot['year']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_vehicle_display_string_formats_correctly(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot([
                'license_plate' => 'XYZ-789',
                'brand' => 'Honda',
                'model' => 'Civic',
            ])
            ->create();

        $displayString = $context->getVehicleDisplayString();

        $this->assertEquals('Honda - Civic - XYZ-789', $displayString);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_vehicle_display_string_handles_missing_snapshot(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0003',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->create([
                'vehicle_snapshot' => null,
            ]);

        $displayString = $context->getVehicleDisplayString();

        $this->assertEquals('Unknown Vehicle', $displayString);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function document_without_context_returns_null(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        // No DocumentVehicleContext created for this document
        $context = DocumentVehicleContext::where('document_id', $document->id)->first();

        $this->assertNull($context);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function context_data_can_be_nullable(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0003',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot()
            ->create([
                'context_data' => null,
            ]);

        $this->assertNull($context->context_data);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function context_belongs_to_document(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0004',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot()
            ->create();

        $retrievedDocument = $context->document;

        $this->assertInstanceOf(Document::class, $retrievedDocument);
        $this->assertEquals($document->id, $retrievedDocument->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_context_value_returns_correct_value(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0005',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot()
            ->create([
                'context_data' => [
                    'next_service_date' => '2025-06-01',
                    'warranty_expiry' => '2026-01-01',
                ],
            ]);

        $this->assertEquals('2025-06-01', $context->getContextValue('next_service_date'));
        $this->assertEquals('2026-01-01', $context->getContextValue('warranty_expiry'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_context_value_returns_default_for_missing_key(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0006',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot()
            ->create([
                'context_data' => [
                    'next_service_date' => '2025-06-01',
                ],
            ]);

        $this->assertNull($context->getContextValue('non_existent_key'));
        $this->assertEquals('default_value', $context->getContextValue('non_existent_key', 'default_value'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_mileage_at_service_returns_correct_value(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0007',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot()
            ->withMileage(75000)
            ->create();

        $this->assertEquals(75000, $context->getMileageAtService());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_mileage_at_service_returns_null_when_not_set(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0008',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot()
            ->create([
                'mileage_at_service' => null,
            ]);

        $this->assertNull($context->getMileageAtService());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_vehicle_id_returns_correct_value(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0009',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $vehicleId = Str::uuid()->toString();

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->withSnapshot()
            ->create([
                'vehicle_id' => $vehicleId,
            ]);

        $this->assertEquals($vehicleId, $context->getVehicleId());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function complete_service_context_factory_state_works(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0010',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $context = DocumentVehicleContext::factory()
            ->for($document)
            ->completeServiceContext()
            ->create();

        $this->assertNotNull($context->vehicle_snapshot);
        $this->assertIsArray($context->vehicle_snapshot);
        $this->assertNotNull($context->mileage_at_service);
        $this->assertIsInt($context->mileage_at_service);
        $this->assertGreaterThan(0, $context->mileage_at_service);
    }
}
