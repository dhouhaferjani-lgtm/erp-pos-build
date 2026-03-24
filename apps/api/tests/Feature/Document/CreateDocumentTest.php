<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CreateDocumentTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => \App\Enums\Vertical::Mechanic,
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

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Set company context for the test
        app(\App\Modules\Company\Services\CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Change Service',
            'sku' => 'SVC-001',
            'type' => ProductType::Service,
            'sale_price' => '50.00',
            'tax_rate' => '20.00',
        ]);
    }

    public function test_partner_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'description' => 'Service',
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['partner_id']);
    }

    public function test_document_date_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'lines' => [
                    [
                        'description' => 'Service',
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['document_date']);
    }

    public function test_lines_are_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
            ]);

        $this->assertApiValidationErrors($response, ['lines']);
    }

    public function test_line_description_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.description']);
    }

    public function test_line_quantity_must_be_positive(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'description' => 'Service',
                        'quantity' => '-1.00',
                        'unit_price' => '100.00',
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.quantity']);
    }

    public function test_can_create_quote(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'currency' => 'EUR',
                'notes' => 'Test quote',
                'lines' => [
                    [
                        'description' => 'Oil Change',
                        'quantity' => '1.00',
                        'unit_price' => '50.00',
                        'tax_rate' => '20.00',
                    ],
                    [
                        'description' => 'Oil Filter',
                        'quantity' => '1.00',
                        'unit_price' => '25.00',
                        'tax_rate' => '20.00',
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'quote')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.partner_id', $this->customer->id)
            ->assertJsonPath('data.subtotal', '75.00')
            ->assertJsonPath('data.tax_amount', '15.00')
            ->assertJsonPath('data.total', '90.00')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'document_number',
                    'type',
                    'status',
                    'partner_id',
                    'document_date',
                    'subtotal',
                    'tax_amount',
                    'total',
                    'currency',
                    'lines' => [
                        '*' => ['id', 'line_number', 'description', 'quantity', 'unit_price', 'tax_rate', 'line_total'],
                    ],
                ],
            ]);

        $this->assertDatabaseHas('documents', [
            'tenant_id' => $this->tenant->id,
            'partner_id' => $this->customer->id,
            'type' => 'quote',
            'status' => 'draft',
        ]);

        $this->assertDatabaseCount('document_lines', 2);
    }

    public function test_can_create_invoice(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => 'EUR',
                'lines' => [
                    [
                        'description' => 'Consulting Services',
                        'quantity' => '2.00',
                        'unit_price' => '100.00',
                        'tax_rate' => '20.00',
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'invoice')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.tax_amount', '40.00')
            ->assertJsonPath('data.total', '240.00');
    }

    public function test_document_number_is_auto_generated(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'description' => 'Service',
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                    ],
                ],
            ]);

        $response->assertCreated();
        $documentNumber = $response->json('data.document_number');

        $this->assertNotNull($documentNumber);
        $this->assertStringStartsWith('QT-', $documentNumber);
    }

    public function test_partner_must_belong_to_same_tenant(): void
    {
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
            'name' => 'Other Customer',
            'type' => PartnerType::Customer,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $otherPartner->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'description' => 'Service',
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['partner_id']);
    }

    public function test_unauthenticated_user_cannot_create_document(): void
    {
        $response = $this->postJson('/api/v1/quotes', [
            'partner_id' => $this->customer->id,
            'document_date' => now()->toDateString(),
            'lines' => [
                [
                    'description' => 'Service',
                    'quantity' => '1.00',
                    'unit_price' => '100.00',
                ],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_create_quote(): void
    {
        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $viewerUser->assignRole('viewer');

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'description' => 'Service',
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                    ],
                ],
            ]);

        $response->assertForbidden();
    }

    public function test_can_create_document_with_product_reference(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'product_id' => $this->product->id,
                        'description' => $this->product->name,
                        'quantity' => '1.00',
                        'unit_price' => $this->product->sale_price,
                        'tax_rate' => $this->product->tax_rate,
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.lines.0.product_id', $this->product->id);
    }

    public function test_can_create_document_with_vehicle(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'vehicle_context' => [
                    'vehicle_id' => $vehicle->id,
                    'snapshot' => [
                        'license_plate' => $vehicle->license_plate,
                        'brand' => $vehicle->brand,
                        'model' => $vehicle->model,
                        'year' => $vehicle->year,
                        'vin' => $vehicle->vin,
                    ],
                    'mileage' => 50000,
                ],
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'description' => 'Oil Change',
                        'quantity' => '1.00',
                        'unit_price' => '50.00',
                        'tax_rate' => '20.00',
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.vehicle_context.vehicle_id', $vehicle->id)
            ->assertJsonPath('data.vehicle_context.snapshot.license_plate', $vehicle->license_plate)
            ->assertJsonPath('data.vehicle_context.snapshot.brand', $vehicle->brand)
            ->assertJsonPath('data.vehicle_context.mileage', 50000);

        $documentId = $response->json('data.id');

        $this->assertDatabaseHas('document_vehicle_contexts', [
            'document_id' => $documentId,
            'vehicle_id' => $vehicle->id,
            'mileage_at_service' => 50000,
        ]);

        // Ensure vehicle_snapshot is populated in database
        $context = DocumentVehicleContext::where('document_id', $documentId)->first();
        $this->assertNotNull($context->vehicle_snapshot);
        $this->assertEquals($vehicle->license_plate, $context->vehicle_snapshot['license_plate']);
    }

    public function test_can_create_document_without_vehicle(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'description' => 'Consulting',
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                        'tax_rate' => '20.00',
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.vehicle_context', null);

        $documentId = $response->json('data.id');

        $this->assertDatabaseMissing('document_vehicle_contexts', [
            'document_id' => $documentId,
        ]);
    }

    public function test_vehicle_must_belong_to_same_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant-vehicle',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX789',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        $otherVehicle = Vehicle::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'vehicle_context' => [
                    'vehicle_id' => $otherVehicle->id,
                    'snapshot' => [
                        'license_plate' => $otherVehicle->license_plate,
                        'brand' => $otherVehicle->brand,
                        'model' => $otherVehicle->model,
                        'year' => $otherVehicle->year,
                        'vin' => $otherVehicle->vin,
                    ],
                    'mileage' => 30000,
                ],
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'description' => 'Service',
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['vehicle_context.vehicle_id']);
    }
}
