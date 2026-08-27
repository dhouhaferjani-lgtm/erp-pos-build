<?php

declare(strict_types=1);

namespace Tests\Feature\Document\Types;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentSequence;
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

class DeliveryNoteDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private Location $location;

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
            'status' => CompanyStatus::Active,
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
        $this->user->givePermissionTo(['deliveries.view', 'deliveries.create', 'deliveries.confirm']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Set company context for the test
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
            'email' => 'partner@example.com',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_delivery_note_can_be_created(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'lines' => [
                [
                    'description' => 'Product A',
                    'quantity' => '5.00',
                    'unit_price' => '0.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals('delivery_note', $response->json('data.type'));
        $response->assertJsonPath('data.document_number', null);
        $this->assertSame(0, DocumentSequence::query()->where('type', DocumentType::DeliveryNote->value)->count());
    }

    public function test_delivery_note_can_reference_sales_order(): void
    {
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-16',
            'source_document_id' => $order->id,
            'lines' => [
                [
                    'description' => 'Product from order',
                    'quantity' => '3.00',
                    'unit_price' => '0.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals($order->id, $response->json('data.source_document_id'));
    }

    public function test_delivery_note_can_be_confirmed(): void
    {
        $deliveryNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'location_id' => $this->location->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'DN-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/delivery-notes/{$deliveryNote->id}/confirm");

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $response->json('data.status'));
    }

    public function test_delivery_note_tracks_quantities(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'lines' => [
                [
                    'description' => 'Item A',
                    'quantity' => '10.00',
                    'unit_price' => '0.00',
                ],
                [
                    'description' => 'Item B',
                    'quantity' => '5.00',
                    'unit_price' => '0.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('data.lines'));
        $this->assertEquals('10.0000', $response->json('data.lines.0.quantity'));
        $this->assertEquals('5.0000', $response->json('data.lines.1.quantity'));
    }

    public function test_delivery_note_has_no_financial_totals(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'lines' => [
                [
                    'description' => 'Free item',
                    'quantity' => '1.00',
                    'unit_price' => '0.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals('0.00', $response->json('data.subtotal'));
        $this->assertEquals('0.00', $response->json('data.total'));
    }
}
