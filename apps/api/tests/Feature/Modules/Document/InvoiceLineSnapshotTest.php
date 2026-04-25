<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for designation_default_snapshot capture in confirmed-document creation.
 *
 * Verifies that:
 * - POST /api/v1/invoices captures designation_default_snapshot from product name
 * - POST /api/v1/invoices captures designation_default_snapshot from service name
 * - The snapshot is independent of the user-supplied description override
 * - PATCH /api/v1/invoices/{id} also captures the snapshot on line replacement
 */
class InvoiceLineSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Snapshot Test Tenant',
            'slug' => 'snapshot-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Snapshot Test Company',
            'legal_name' => 'Snapshot Test Company LLC',
            'tax_id' => 'SNAPTAX123',
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
            'name' => 'Snapshot Test User',
            'email' => 'snapshot-test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Snapshot Partner',
            'type' => 'customer',
            'code' => 'SNAPTST001',
        ]);
    }

    #[Test]
    public function creating_invoice_line_with_product_captures_designation_snapshot(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Michelin Pilot Sport 4',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Michelin Pilot Sport 4',
                        'quantity' => 4,
                        'unit_price' => 150.00,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $invoiceId = $response->json('data.id');

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $invoiceId,
            'designation_default_snapshot' => 'Michelin Pilot Sport 4',
        ]);
    }

    #[Test]
    public function creating_invoice_line_with_service_captures_designation_snapshot(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Wheel Alignment Service',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'service_id' => $service->id,
                        'description' => 'Wheel Alignment Service',
                        'quantity' => 1,
                        'unit_price' => 80.00,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $invoiceId = $response->json('data.id');

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $invoiceId,
            'designation_default_snapshot' => 'Wheel Alignment Service',
        ]);
    }

    #[Test]
    public function snapshot_is_product_name_even_when_description_is_overridden(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Bosch Oil Filter',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Custom description set by user',
                        'quantity' => 1,
                        'unit_price' => 12.00,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $invoiceId = $response->json('data.id');

        // Description is what the user set
        $this->assertDatabaseHas('document_lines', [
            'document_id' => $invoiceId,
            'description' => 'Custom description set by user',
        ]);

        // Snapshot is always the canonical product name
        $this->assertDatabaseHas('document_lines', [
            'document_id' => $invoiceId,
            'designation_default_snapshot' => 'Bosch Oil Filter',
        ]);
    }

    #[Test]
    public function updating_invoice_lines_captures_designation_snapshot(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'NGK Spark Plug',
        ]);

        // Create invoice first
        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'description' => 'Generic part',
                        'quantity' => 1,
                        'unit_price' => 10.00,
                    ],
                ],
            ]);

        $createResponse->assertStatus(201);
        $invoiceId = $createResponse->json('data.id');

        // Update the invoice with a product-linked line
        $updateResponse = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/invoices/{$invoiceId}", [
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'NGK Spark Plug',
                        'quantity' => 4,
                        'unit_price' => 8.50,
                    ],
                ],
            ]);

        $updateResponse->assertStatus(200);

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $invoiceId,
            'designation_default_snapshot' => 'NGK Spark Plug',
        ]);
    }
}
