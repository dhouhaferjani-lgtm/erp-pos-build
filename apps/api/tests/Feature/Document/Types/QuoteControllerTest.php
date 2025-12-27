<?php

declare(strict_types=1);

namespace Tests\Feature\Document\Types;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
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

/**
 * Tests for QuoteController functionality.
 *
 * These tests verify that the QuoteController extracts the exact same
 * behavior from DocumentController for quote operations.
 */
class QuoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.uniqid(),
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
            'email' => 'user-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'quotes.view',
            'quotes.create',
            'quotes.update',
            'quotes.delete',
            'quotes.convert',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Set company context for the test
        app(\App\Modules\Company\Services\CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
            'email' => 'partner-'.uniqid().'@example.com',
        ]);
    }

    public function test_can_list_quotes(): void
    {
        // Create some quotes
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-2025-0002',
            'document_date' => '2025-01-16',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/quotes');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'type',
                    'status',
                    'document_number',
                ],
            ],
            'meta' => ['per_page', 'has_more'],
            'links' => ['next', 'prev'],
        ]);
    }

    public function test_can_filter_quotes_by_status(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-2025-0002',
            'document_date' => '2025-01-16',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/quotes?status=draft');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('draft', $response->json('data.0.status'));
    }

    public function test_can_create_quote(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'lines' => [
                [
                    'description' => 'Test service',
                    'quantity' => '2.00',
                    'unit_price' => '100.00',
                    'tax_rate' => '20.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals('quote', $response->json('data.type'));
        $this->assertEquals('draft', $response->json('data.status'));
        $this->assertEquals('200.00', $response->json('data.subtotal'));
        $this->assertEquals('40.00', $response->json('data.tax_amount'));
        $this->assertEquals('240.00', $response->json('data.total'));
        $this->assertNotNull($response->json('data.document_number'));
    }

    public function test_can_show_quote(): void
    {
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/quotes/{$quote->id}");

        $response->assertStatus(200);
        $this->assertEquals($quote->id, $response->json('data.id'));
        $this->assertEquals('quote', $response->json('data.type'));
    }

    public function test_show_returns_404_for_non_quote(): void
    {
        // Create an invoice instead of a quote
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        // Trying to get it via quote endpoint should fail
        $response = $this->actingAs($this->user)->getJson("/api/v1/quotes/{$invoice->id}");

        $response->assertStatus(404);
    }

    public function test_can_update_draft_quote(): void
    {
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
            'notes' => 'Original notes',
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/quotes/{$quote->id}", [
            'notes' => 'Updated notes',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Updated notes', $response->json('data.notes'));
    }

    public function test_can_update_quote_lines(): void
    {
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'total' => '100.00',
        ]);

        $quote->lines()->create([
            'line_number' => 1,
            'description' => 'Original line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'line_total' => '100.00',
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/quotes/{$quote->id}", [
            'lines' => [
                [
                    'description' => 'New line',
                    'quantity' => '2.00',
                    'unit_price' => '150.00',
                    'tax_rate' => '10.00',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.lines'));
        $this->assertEquals('New line', $response->json('data.lines.0.description'));
        $this->assertEquals('300.00', $response->json('data.subtotal'));
    }

    public function test_can_update_confirmed_quote(): void
    {
        // Note: According to DocumentStatus::isEditable(), Confirmed quotes are still editable
        // Only Posted/Paid/Cancelled quotes are non-editable
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/quotes/{$quote->id}", [
            'notes' => 'Updated notes',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Updated notes', $response->json('data.notes'));
    }

    public function test_cannot_update_posted_quote(): void
    {
        // Only Posted documents (and beyond) are non-editable
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Posted,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/quotes/{$quote->id}", [
            'notes' => 'Updated notes',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('DOCUMENT_NOT_EDITABLE', $response->json('error.code'));
    }

    public function test_can_delete_draft_quote(): void
    {
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/quotes/{$quote->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('documents', ['id' => $quote->id]);
    }

    public function test_cannot_delete_confirmed_quote(): void
    {
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/quotes/{$quote->id}");

        $response->assertStatus(422);
        $this->assertEquals('DOCUMENT_NOT_DELETABLE', $response->json('error.code'));
    }

    public function test_can_confirm_draft_quote(): void
    {
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quote->id}/confirm");

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $response->json('data.status'));

        $quote->refresh();
        $this->assertNotNull($quote->confirmed_at);
        $this->assertEquals($this->user->id, $quote->confirmed_by);
    }

    public function test_confirm_is_idempotent(): void
    {
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quote->id}/confirm");

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $response->json('data.status'));
    }

    public function test_cannot_confirm_posted_quote(): void
    {
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Posted,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quote->id}/confirm");

        $response->assertStatus(422);
        $this->assertEquals('INVALID_STATUS_TRANSITION', $response->json('error.code'));
    }

    public function test_can_create_quote_with_vehicle_context(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'vehicle_context' => [
                'vehicle_id' => $vehicle->id,
                'mileage' => 50000,
            ],
            'lines' => [
                [
                    'description' => 'Oil change',
                    'quantity' => '1.00',
                    'unit_price' => '50.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.vehicle_context'));
        $this->assertEquals($vehicle->id, $response->json('data.vehicle_context.vehicle_id'));
    }

    public function test_can_create_quote_with_product(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => '1.00',
                    'unit_price' => '100.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals($product->id, $response->json('data.lines.0.product_id'));
    }

    public function test_can_filter_quotes_by_partner(): void
    {
        $partner2 = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Another Partner',
            'type' => PartnerType::Customer,
            'email' => 'another-'.uniqid().'@example.com',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner2->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0002',
            'document_date' => '2025-01-16',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/quotes?partner_id={$this->partner->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals($this->partner->id, $response->json('data.0.partner_id'));
    }

    public function test_can_search_quotes_by_document_number(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0002',
            'document_date' => '2025-01-16',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/quotes?search=0001');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('QT-2025-0001', $response->json('data.0.document_number'));
    }
}
