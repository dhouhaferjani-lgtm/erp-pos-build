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
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class QuoteDocumentTest extends TestCase
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
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete', 'quotes.convert']);

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
            'email' => 'partner@example.com',
        ]);
    }

    public function test_quote_can_have_validity_period(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/quotes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'valid_until' => '2025-02-15',
            'lines' => [
                [
                    'description' => 'Test service',
                    'quantity' => '1.00',
                    'unit_price' => '100.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals('2025-02-15', $response->json('data.valid_until'));
    }

    public function test_quote_can_be_converted_to_sales_order(): void
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

        $response = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quote->id}/convert-to-order");

        $response->assertStatus(201);
        $this->assertEquals('sales_order', $response->json('data.type'));
        $this->assertEquals($quote->id, $response->json('data.source_document_id'));
    }

    public function test_draft_quote_cannot_be_converted(): void
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

        $response = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quote->id}/convert-to-order");

        $response->assertStatus(422);
        $this->assertEquals('QUOTE_NOT_CONFIRMED', $response->json('error.code'));
    }

    public function test_quote_can_be_confirmed(): void
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
    }

    public function test_already_confirmed_quote_is_idempotent(): void
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

        // Confirming an already-confirmed document is idempotent - returns 200
        $response = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quote->id}/confirm");

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $response->json('data.status'));
    }

    public function test_quote_preserves_lines_when_converted(): void
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
            'subtotal' => '200.00',
            'total' => '200.00',
        ]);

        $quote->lines()->create([
            'line_number' => 1,
            'description' => 'Service A',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'line_total' => '100.00',
        ]);

        $quote->lines()->create([
            'line_number' => 2,
            'description' => 'Service B',
            'quantity' => '2.00',
            'unit_price' => '50.00',
            'line_total' => '100.00',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/quotes/{$quote->id}/convert-to-order");

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('data.lines'));
        $this->assertEquals('Service A', $response->json('data.lines.0.description'));
        $this->assertEquals('Service B', $response->json('data.lines.1.description'));
    }
}
