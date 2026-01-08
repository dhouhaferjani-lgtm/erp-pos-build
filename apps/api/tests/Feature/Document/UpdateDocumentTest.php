<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UpdateDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Document $quote;

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

        $this->quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        DocumentLine::create([
            'document_id' => $this->quote->id,
            'line_number' => 1,
            'description' => 'Original Service',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);
    }

    public function test_can_update_draft_document_notes(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'notes' => 'Updated notes',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.notes', 'Updated notes');

        $this->assertDatabaseHas('documents', [
            'id' => $this->quote->id,
            'notes' => 'Updated notes',
        ]);
    }

    public function test_can_update_document_lines(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'lines' => [
                    [
                        'description' => 'Updated Service',
                        'quantity' => '2.00',
                        'unit_price' => '75.00',
                        'tax_rate' => '20.00',
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.subtotal', '150.00')
            ->assertJsonPath('data.tax_amount', '30.00')
            ->assertJsonPath('data.total', '180.00')
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.description', 'Updated Service');
    }

    public function test_can_change_partner_on_draft_document(): void
    {
        $newCustomer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Jane Smith',
            'type' => PartnerType::Customer,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'partner_id' => $newCustomer->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.partner_id', $newCustomer->id);
    }

    public function test_cannot_update_posted_document(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0001',
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/invoices/{$invoice->id}", [
                'notes' => 'Trying to update posted invoice',
            ]);

        $response->assertUnprocessable()
            ->assertJson([
                'error' => [
                    'code' => 'DOCUMENT_NOT_EDITABLE',
                    'message' => 'Posted documents cannot be modified',
                ],
            ]);
    }

    public function test_cannot_update_cancelled_document(): void
    {
        $this->quote->update(['status' => DocumentStatus::Cancelled]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'notes' => 'Trying to update cancelled document',
            ]);

        $response->assertUnprocessable()
            ->assertJson([
                'error' => [
                    'code' => 'DOCUMENT_NOT_EDITABLE',
                ],
            ]);
    }

    public function test_returns_404_for_nonexistent_document(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$fakeId}", [
                'notes' => 'Test',
            ]);

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_update_document(): void
    {
        $response = $this->patchJson("/api/v1/quotes/{$this->quote->id}", [
            'notes' => 'Test',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_update_quote(): void
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
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'notes' => 'Test',
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_update_document_from_another_tenant(): void
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

        $otherDocument = Document::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$otherDocument->id}", [
                'notes' => 'Hacking attempt',
            ]);

        $response->assertNotFound();
    }

    public function test_can_add_vehicle_to_document(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
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
            ]);

        $response->assertOk()
            ->assertJsonPath('data.vehicle_context.vehicle_id', $vehicle->id)
            ->assertJsonPath('data.vehicle_context.snapshot.license_plate', $vehicle->license_plate)
            ->assertJsonPath('data.vehicle_context.snapshot.brand', $vehicle->brand)
            ->assertJsonPath('data.vehicle_context.mileage', 50000);

        $this->assertDatabaseHas('document_vehicle_contexts', [
            'document_id' => $this->quote->id,
            'vehicle_id' => $vehicle->id,
        ]);
    }

    public function test_can_change_vehicle_on_document(): void
    {
        $vehicle1 = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
        ]);

        $vehicle2 = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
        ]);

        // Add vehicle1 first using vehicle_context
        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'vehicle_context' => [
                    'vehicle_id' => $vehicle1->id,
                    'snapshot' => [
                        'license_plate' => $vehicle1->license_plate,
                        'brand' => $vehicle1->brand,
                        'model' => $vehicle1->model,
                        'year' => $vehicle1->year,
                        'vin' => $vehicle1->vin,
                    ],
                    'mileage' => 30000,
                ],
            ]);

        // Update to vehicle2
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'vehicle_context' => [
                    'vehicle_id' => $vehicle2->id,
                    'snapshot' => [
                        'license_plate' => $vehicle2->license_plate,
                        'brand' => $vehicle2->brand,
                        'model' => $vehicle2->model,
                        'year' => $vehicle2->year,
                        'vin' => $vehicle2->vin,
                    ],
                    'mileage' => 60000,
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.vehicle_context.vehicle_id', $vehicle2->id)
            ->assertJsonPath('data.vehicle_context.snapshot.license_plate', $vehicle2->license_plate)
            ->assertJsonPath('data.vehicle_context.mileage', 60000);

        $this->assertDatabaseHas('document_vehicle_contexts', [
            'document_id' => $this->quote->id,
            'vehicle_id' => $vehicle2->id,
        ]);

        $this->assertDatabaseMissing('document_vehicle_contexts', [
            'document_id' => $this->quote->id,
            'vehicle_id' => $vehicle1->id,
        ]);
    }

    public function test_can_remove_vehicle_from_document(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
        ]);

        // Add vehicle first using vehicle_context
        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'vehicle_context' => [
                    'vehicle_id' => $vehicle->id,
                    'snapshot' => [
                        'license_plate' => $vehicle->license_plate,
                        'brand' => $vehicle->brand,
                        'model' => $vehicle->model,
                        'year' => $vehicle->year,
                        'vin' => $vehicle->vin,
                    ],
                    'mileage' => 40000,
                ],
            ]);

        // Remove vehicle by sending null
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'vehicle_context' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.vehicle_context', null);

        $this->assertDatabaseMissing('document_vehicle_contexts', [
            'document_id' => $this->quote->id,
        ]);
    }
}
