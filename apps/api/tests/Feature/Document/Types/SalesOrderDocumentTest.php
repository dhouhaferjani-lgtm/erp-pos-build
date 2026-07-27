<?php

declare(strict_types=1);

namespace Tests\Feature\Document\Types;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
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

class SalesOrderDocumentTest extends TestCase
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
        $this->user->givePermissionTo(['orders.view', 'orders.create', 'orders.update', 'orders.delete', 'orders.confirm', 'invoices.create']);

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
    }

    public function test_sales_order_can_be_created(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/orders', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'lines' => [
                [
                    'description' => 'Test product',
                    'quantity' => '2.00',
                    'unit_price' => '50.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals('sales_order', $response->json('data.type'));
    }

    public function test_sales_order_can_be_confirmed(): void
    {
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SO-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/orders/{$order->id}/confirm");

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $response->json('data.status'));
    }

    public function test_confirmed_order_can_be_converted_to_invoice(): void
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

        $response = $this->actingAs($this->user)->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");

        $response->assertStatus(201);
        $this->assertEquals('invoice', $response->json('data.type'));
        $this->assertEquals($order->id, $response->json('data.source_document_id'));
    }

    public function test_draft_order_cannot_be_converted_to_invoice(): void
    {
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SO-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");

        $response->assertStatus(422);
        $this->assertEquals('ORDER_NOT_CONFIRMED', $response->json('error.code'));
    }

    public function test_order_preserves_lines_when_converted_to_invoice(): void
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
            'subtotal' => '150.00',
            'total' => '150.00',
        ]);

        $order->lines()->create([
            'line_number' => 1,
            'description' => 'Product A',
            'quantity' => '3.00',
            'unit_price' => '50.00',
            'line_total' => '150.00',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");

        $response->assertStatus(201);
        $this->assertCount(1, $response->json('data.lines'));
        $this->assertEquals('Product A', $response->json('data.lines.0.description'));
        $this->assertEquals('3.0000', $response->json('data.lines.0.quantity'));
    }

    public function test_order_can_have_reference(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/orders', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'reference' => 'PO-12345',
            'lines' => [
                [
                    'description' => 'Test product',
                    'quantity' => '1.00',
                    'unit_price' => '100.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals('PO-12345', $response->json('data.reference'));
    }

    public function test_sales_order_show_exposes_delivered_quantity(): void
    {
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-2025-0002',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $order->lines()->create([
            'line_number' => 1,
            'description' => 'Partially delivered product',
            'quantity' => '3.0000',
            'quantity_delivered' => '2.5000',
            'unit_price' => '50.000',
            'line_total' => '150.000',
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity_delivered', '2.5000');
    }

    public function test_eur_get_patch_round_trip_preserves_scale_four_quantity(): void
    {
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SO-2025-0003',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $line = $order->lines()->create([
            'line_number' => 1,
            'description' => 'Fractional product',
            'quantity' => '0.1250',
            'unit_price' => '8.000',
            'line_total' => '1.000',
        ]);

        $showResponse = $this->actingAs($this->user)
            ->getJson("/api/v1/orders/{$order->id}");
        $updateResponse = $this->actingAs($this->user)
            ->patchJson("/api/v1/orders/{$order->id}", [
                'notes' => 'Unrelated edit',
            ]);

        $showResponse->assertOk()
            ->assertJsonPath('data.lines.0.quantity', '0.1250');
        $updateResponse->assertOk()
            ->assertJsonPath('data.notes', 'Unrelated edit')
            ->assertJsonPath('data.lines.0.quantity', '0.1250');
        self::assertSame('0.1250', $line->fresh()?->quantity);
    }
}
