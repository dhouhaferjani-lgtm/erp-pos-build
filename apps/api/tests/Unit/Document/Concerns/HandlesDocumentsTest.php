<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Concerns;

use App\Http\Controllers\Controller;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Application\Services\VehicleContextBuilder;
use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Test suite for the HandlesDocuments trait.
 *
 * Uses a mock controller that implements the trait to test all methods.
 */
class HandlesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private MockDocumentController $controller;

    private CompanyContext $companyContext;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant using factory
        $this->tenant = Tenant::factory()->create();

        // Create company using factory
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create partner using factory
        $this->partner = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Setup company context
        $this->companyContext = app(CompanyContext::class);
        $this->companyContext->setCompanyId($this->company->id);

        // Create the mock controller
        $this->controller = new MockDocumentController($this->companyContext);
    }

    /**
     * Create a document with default fiscal fields set.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createDocument(array $attributes): Document
    {
        $defaults = [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'document_date' => now(),
            'currency' => 'EUR',
        ];

        return Document::create(array_merge($defaults, $attributes));
    }

    public function test_base_query_scopes_to_current_company(): void
    {
        // Create documents for our company
        $ourDocument = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        // Create another company with a document
        $otherCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $otherPartner = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $otherDocument = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-002',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        // The base query should only return our company's document
        $documents = $this->controller->publicBaseQuery()->get();

        $this->assertCount(1, $documents);
        $this->assertEquals($ourDocument->id, $documents->first()->id);
    }

    public function test_document_response_returns_correct_format(): void
    {
        $document = $this->createDocument([
            'document_number' => 'INV-001',
            'total' => '100.00',
        ]);

        // Load relations for the DTO
        $document->load(['lines', 'vehicleContext']);

        $response = $this->controller->publicDocumentResponse($document);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('timestamp', $data['meta']);
        $this->assertEquals('INV-001', $data['data']['document_number']);
    }

    public function test_document_created_response_returns_201(): void
    {
        $document = $this->createDocument([
            'document_number' => 'INV-001',
        ]);

        $document->load(['lines', 'vehicleContext']);

        $response = $this->controller->publicDocumentCreatedResponse($document);

        $this->assertEquals(201, $response->getStatusCode());
    }

    public function test_default_relations_returns_expected_array(): void
    {
        $relations = $this->controller->publicDefaultRelations();

        $this->assertIsArray($relations);
        $this->assertContains('lines', $relations);
        // vehicleContext is conditionally included based on Vehicle module enablement
    }

    public function test_detail_relations_includes_allocations(): void
    {
        $relations = $this->controller->publicDetailRelations();

        $this->assertIsArray($relations);
        $this->assertContains('lines', $relations);
        $this->assertContains('allocations.payment.paymentMethod', $relations);
        // vehicleContext is conditionally included based on Vehicle module enablement
    }

    public function test_apply_filters_with_status(): void
    {
        // Create draft and confirmed documents
        $draftDoc = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $confirmedDoc = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-002',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $request = Request::create('/api/documents', 'GET', ['status' => 'draft']);

        $query = $this->controller->publicBaseQuery();
        $filteredQuery = $this->controller->publicApplyFilters($query, $request);

        $results = $filteredQuery->get();

        $this->assertCount(1, $results);
        $this->assertEquals($draftDoc->id, $results->first()->id);
    }

    public function test_apply_filters_with_partner_id(): void
    {
        // Create another partner
        $otherPartner = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $doc1 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $doc2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-002',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $request = Request::create('/api/documents', 'GET', ['partner_id' => $this->partner->id]);

        $query = $this->controller->publicBaseQuery();
        $filteredQuery = $this->controller->publicApplyFilters($query, $request);

        $results = $filteredQuery->get();

        $this->assertCount(1, $results);
        $this->assertEquals($doc1->id, $results->first()->id);
    }

    public function test_apply_filters_with_search(): void
    {
        $doc1 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001-ABC',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $doc2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-002-XYZ',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $request = Request::create('/api/documents', 'GET', ['search' => 'ABC']);

        $query = $this->controller->publicBaseQuery();
        $filteredQuery = $this->controller->publicApplyFilters($query, $request);

        $results = $filteredQuery->get();

        $this->assertCount(1, $results);
        $this->assertEquals($doc1->id, $results->first()->id);
    }

    public function test_apply_filters_with_date_range(): void
    {
        $oldDoc = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now()->subDays(10),
            'currency' => 'EUR',
        ]);

        $recentDoc = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-002',
            'document_date' => now()->subDay(),
            'currency' => 'EUR',
        ]);

        $request = Request::create('/api/documents', 'GET', [
            'date_from' => now()->subDays(5)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $query = $this->controller->publicBaseQuery();
        $filteredQuery = $this->controller->publicApplyFilters($query, $request);

        $results = $filteredQuery->get();

        $this->assertCount(1, $results);
        $this->assertEquals($recentDoc->id, $results->first()->id);
    }

    public function test_apply_filters_with_product_id(): void
    {
        // Create two products using factory
        $product1 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $product2 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $doc1 = $this->createDocument([
            'document_number' => 'INV-001',
        ]);

        DocumentLine::create([
            'document_id' => $doc1->id,
            'product_id' => $product1->id,
            'line_number' => 1,
            'description' => 'Test Product',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'line_total' => '100.00',
        ]);

        $doc2 = $this->createDocument([
            'document_number' => 'INV-002',
        ]);

        DocumentLine::create([
            'document_id' => $doc2->id,
            'product_id' => $product2->id,
            'line_number' => 1,
            'description' => 'Other Product',
            'quantity' => '1.00',
            'unit_price' => '50.00',
            'line_total' => '50.00',
        ]);

        $request = Request::create('/api/documents', 'GET', ['product_id' => $product1->id]);

        $query = $this->controller->publicBaseQuery();
        $filteredQuery = $this->controller->publicApplyFilters($query, $request);

        $results = $filteredQuery->get();

        $this->assertCount(1, $results);
        $this->assertEquals($doc1->id, $results->first()->id);
    }

    public function test_attach_vehicle_context_creates_context(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        // Create a vehicle for the test using factory
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
        ]);

        $vehicleContextData = [
            'vehicle_id' => $vehicle->id,
            'mileage' => 50000,
        ];

        $this->controller->publicAttachVehicleContext($document, $vehicleContextData);

        $this->assertNotNull($document->fresh()->vehicleContext);
        $this->assertEquals($vehicle->id, $document->fresh()->vehicleContext->vehicle_id);
        $this->assertEquals(50000, $document->fresh()->vehicleContext->mileage_at_service);
    }

    public function test_attach_vehicle_context_deletes_when_null(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        // Create existing vehicle context
        DocumentVehicleContext::create([
            'document_id' => $document->id,
            'vehicle_id' => 'existing-vehicle-id',
            'vehicle_snapshot' => ['brand' => 'Honda', 'model' => 'Civic'],
        ]);

        $this->assertNotNull($document->fresh()->vehicleContext);

        // Pass null to delete it
        $this->controller->publicAttachVehicleContext($document, null, true);

        $this->assertNull($document->fresh()->vehicleContext);
    }

    public function test_attach_vehicle_context_skips_when_not_explicitly_provided(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        // Create existing vehicle context
        DocumentVehicleContext::create([
            'document_id' => $document->id,
            'vehicle_id' => 'existing-vehicle-id',
            'vehicle_snapshot' => ['brand' => 'Honda', 'model' => 'Civic'],
        ]);

        $originalContext = $document->fresh()->vehicleContext;

        // Pass null but indicate it was NOT explicitly provided
        $this->controller->publicAttachVehicleContext($document, null, false);

        // Context should remain unchanged
        $this->assertNotNull($document->fresh()->vehicleContext);
        $this->assertEquals($originalContext->id, $document->fresh()->vehicleContext->id);
    }

    public function test_not_found_response_format(): void
    {
        $response = $this->controller->publicNotFoundResponse('Invoice');

        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('NOT_FOUND', $data['error']['code']);
        $this->assertEquals('Invoice not found', $data['error']['message']);
    }

    public function test_validation_error_response_format(): void
    {
        $response = $this->controller->publicValidationErrorResponse('CUSTOM_ERROR', 'Custom message');

        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('CUSTOM_ERROR', $data['error']['code']);
        $this->assertEquals('Custom message', $data['error']['message']);
    }

    public function test_fiscal_immutability_error_response(): void
    {
        $response = $this->controller->publicFiscalImmutabilityErrorResponse();

        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertEquals('DOCUMENT_SEALED', $data['error']['code']);
        $this->assertStringContainsString('Sealed fiscal documents', $data['error']['message']);
    }

    public function test_not_editable_error_response(): void
    {
        $response = $this->controller->publicNotEditableErrorResponse();

        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertEquals('DOCUMENT_NOT_EDITABLE', $data['error']['code']);
    }

    public function test_not_deletable_error_response(): void
    {
        $response = $this->controller->publicNotDeletableErrorResponse();

        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertEquals('DOCUMENT_NOT_DELETABLE', $data['error']['code']);
    }

    public function test_fiscal_not_deletable_error_response(): void
    {
        $response = $this->controller->publicFiscalNotDeletableErrorResponse();

        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertEquals('FISCAL_DOCUMENT_NOT_DELETABLE', $data['error']['code']);
    }

    public function test_create_vehicle_context(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        // Create a vehicle for the test using factory
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'brand' => 'Honda',
            'model' => 'Accord',
        ]);

        $vehicleContextData = [
            'vehicle_id' => $vehicle->id,
            'mileage' => 10000,
        ];

        $this->controller->publicCreateVehicleContext(
            $document,
            $vehicleContextData,
            $this->tenant->id,
            $this->company->id
        );

        $context = $document->fresh()->vehicleContext;

        $this->assertNotNull($context);
        $this->assertEquals($vehicle->id, $context->vehicle_id);
        $this->assertEquals(10000, $context->mileage_at_service);
        $this->assertIsArray($context->vehicle_snapshot);
        $this->assertEquals('Honda', $context->vehicle_snapshot['brand']);
        $this->assertEquals('Accord', $context->vehicle_snapshot['model']);
    }

    public function test_apply_filters_with_invalid_status_is_ignored(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $request = Request::create('/api/documents', 'GET', ['status' => 'invalid_status']);

        $query = $this->controller->publicBaseQuery();
        $filteredQuery = $this->controller->publicApplyFilters($query, $request);

        $results = $filteredQuery->get();

        // Invalid status should be ignored, all documents returned
        $this->assertCount(1, $results);
    }

    public function test_apply_filters_with_empty_values_are_ignored(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $request = Request::create('/api/documents', 'GET', [
            'status' => '',
            'partner_id' => '',
            'search' => '',
        ]);

        $query = $this->controller->publicBaseQuery();
        $filteredQuery = $this->controller->publicApplyFilters($query, $request);

        $results = $filteredQuery->get();

        // Empty values should be ignored
        $this->assertCount(1, $results);
    }
}

/**
 * Mock controller that uses the HandlesDocuments trait for testing.
 */
class MockDocumentController extends Controller
{
    use HandlesDocuments;

    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    protected function getCompanyContext(): CompanyContext
    {
        return $this->companyContext;
    }

    // Public wrappers for protected methods to enable testing

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Document>
     */
    public function publicBaseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->baseQuery();
    }

    public function publicDocumentResponse(Document $document, int $statusCode = 200): JsonResponse
    {
        return $this->documentResponse($document, $statusCode);
    }

    public function publicDocumentCreatedResponse(Document $document): JsonResponse
    {
        return $this->documentCreatedResponse($document);
    }

    /**
     * @return list<string>
     */
    public function publicDefaultRelations(): array
    {
        return $this->defaultRelations();
    }

    /**
     * @return list<string>
     */
    public function publicDetailRelations(): array
    {
        return $this->detailRelations();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Document>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Document>
     */
    public function publicApplyFilters(\Illuminate\Database\Eloquent\Builder $query, Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return $this->applyFilters($query, $request);
    }

    /**
     * @param  array{vehicle_id: string, mileage?: int}|null  $vehicleContextData
     */
    public function publicAttachVehicleContext(
        Document $document,
        ?array $vehicleContextData,
        bool $wasExplicitlyProvided = true
    ): void {
        $vehicleContextBuilder = app(VehicleContextBuilder::class);
        $this->attachVehicleContext($document, $vehicleContextData, $wasExplicitlyProvided, $vehicleContextBuilder);
    }

    /**
     * @param  array{vehicle_id: string, mileage?: int}  $vehicleContextData
     */
    public function publicCreateVehicleContext(
        Document $document,
        array $vehicleContextData,
        string $tenantId,
        string $companyId
    ): void {
        $vehicleContextBuilder = app(VehicleContextBuilder::class);
        $this->createVehicleContext($document, $vehicleContextData, $tenantId, $companyId, $vehicleContextBuilder);
    }

    public function publicNotFoundResponse(string $resource = 'Document'): JsonResponse
    {
        return $this->notFoundResponse($resource);
    }

    public function publicValidationErrorResponse(string $code, string $message): JsonResponse
    {
        return $this->validationErrorResponse($code, $message);
    }

    public function publicFiscalImmutabilityErrorResponse(): JsonResponse
    {
        return $this->fiscalImmutabilityErrorResponse();
    }

    public function publicNotEditableErrorResponse(): JsonResponse
    {
        return $this->notEditableErrorResponse();
    }

    public function publicNotDeletableErrorResponse(): JsonResponse
    {
        return $this->notDeletableErrorResponse();
    }

    public function publicFiscalNotDeletableErrorResponse(): JsonResponse
    {
        return $this->fiscalNotDeletableErrorResponse();
    }
}
