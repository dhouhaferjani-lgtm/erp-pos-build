<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for designation_default_snapshot capture in all document types.
 *
 * Verifies that:
 * - POST /api/v1/quotes captures designation_default_snapshot from product name
 * - PATCH /api/v1/quotes/{id} also captures the snapshot on line replacement
 * - POST /api/v1/orders captures designation_default_snapshot from product name
 * - PATCH /api/v1/orders/{id} also captures the snapshot on line replacement
 * - POST /api/v1/purchase-orders captures designation_default_snapshot from product name
 * - PATCH /api/v1/purchase-orders/{id} also captures the snapshot on line replacement
 * - POST /api/v1/delivery-notes captures designation_default_snapshot from product name
 * - POST /api/v1/return-notes captures designation_default_snapshot from product name
 * - PATCH /api/v1/return-notes/{id} also captures the snapshot on line replacement
 * - CreditNoteService::createStandaloneCreditNote() captures designation_default_snapshot
 */
class DocumentLineSnapshotTest extends TestCase
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
            'name' => 'Line Snapshot Test Tenant',
            'slug' => 'line-snapshot-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Line Snapshot Test Company',
            'legal_name' => 'Line Snapshot Test Company LLC',
            'tax_id' => 'LSNAPTAX123',
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
            'name' => 'Line Snapshot Test User',
            'email' => 'line-snapshot-test@example.com',
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

        // Grant permissions that the routes require but the admin role seeder may not include.
        // These permissions are used by return-notes update/delete routes but were not seeded.
        $editPerm = Permission::firstOrCreate(
            ['name' => 'deliveries.edit', 'guard_name' => 'sanctum']
        );
        $deletePerm = Permission::firstOrCreate(
            ['name' => 'deliveries.delete', 'guard_name' => 'sanctum']
        );
        $this->user->givePermissionTo([$editPerm, $deletePerm]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Line Snapshot Partner',
            'type' => 'customer',
            'code' => 'LSNAPTST001',
        ]);
    }

    // -------------------------------------------------------------------------
    // Quote
    // -------------------------------------------------------------------------

    #[Test]
    public function creating_quote_line_with_product_captures_designation_snapshot(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Continental SportContact 7',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Continental SportContact 7',
                        'quantity' => 2,
                        'unit_price' => 120.00,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $documentId = $response->json('data.id');

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'designation_default_snapshot' => 'Continental SportContact 7',
        ]);
    }

    #[Test]
    public function updating_quote_lines_captures_designation_snapshot(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Pirelli P Zero',
        ]);

        // Create a quote first (no product)
        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'description' => 'Generic tyre',
                        'quantity' => 1,
                        'unit_price' => 80.00,
                    ],
                ],
            ]);

        $createResponse->assertStatus(201);
        $documentId = $createResponse->json('data.id');

        // Update with a product-linked line
        $updateResponse = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$documentId}", [
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Pirelli P Zero',
                        'quantity' => 4,
                        'unit_price' => 200.00,
                    ],
                ],
            ]);

        $updateResponse->assertStatus(200);

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'designation_default_snapshot' => 'Pirelli P Zero',
        ]);
    }

    // -------------------------------------------------------------------------
    // Sales Order
    // -------------------------------------------------------------------------

    #[Test]
    public function creating_sales_order_line_with_product_captures_designation_snapshot(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Bosch Brake Pad Set',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/orders', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Bosch Brake Pad Set',
                        'quantity' => 1,
                        'unit_price' => 55.00,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $documentId = $response->json('data.id');

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'designation_default_snapshot' => 'Bosch Brake Pad Set',
        ]);
    }

    #[Test]
    public function updating_sales_order_lines_captures_designation_snapshot(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'NGK Iridium Spark Plug',
        ]);

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/orders', [
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
        $documentId = $createResponse->json('data.id');

        $updateResponse = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/orders/{$documentId}", [
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'NGK Iridium Spark Plug',
                        'quantity' => 4,
                        'unit_price' => 12.00,
                    ],
                ],
            ]);

        $updateResponse->assertStatus(200);

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'designation_default_snapshot' => 'NGK Iridium Spark Plug',
        ]);
    }

    // -------------------------------------------------------------------------
    // Purchase Order
    // -------------------------------------------------------------------------

    #[Test]
    public function creating_purchase_order_line_with_product_captures_designation_snapshot(): void
    {
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Auto Parts Supplier',
            'type' => 'supplier',
            'code' => 'LSNAPSUP001',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Valeo Clutch Kit',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'partner_id' => $supplier->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Valeo Clutch Kit',
                        'quantity' => 5,
                        'unit_price' => 180.00,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $documentId = $response->json('data.id');

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'designation_default_snapshot' => 'Valeo Clutch Kit',
        ]);
    }

    #[Test]
    public function updating_purchase_order_lines_captures_designation_snapshot(): void
    {
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Parts Distributor',
            'type' => 'supplier',
            'code' => 'LSNAPSUP002',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Febi Bilstein Shock Absorber',
        ]);

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'partner_id' => $supplier->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'description' => 'Generic suspension part',
                        'quantity' => 1,
                        'unit_price' => 50.00,
                    ],
                ],
            ]);

        $createResponse->assertStatus(201);
        $documentId = $createResponse->json('data.id');

        $updateResponse = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/purchase-orders/{$documentId}", [
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Febi Bilstein Shock Absorber',
                        'quantity' => 2,
                        'unit_price' => 95.00,
                    ],
                ],
            ]);

        $updateResponse->assertStatus(200);

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'designation_default_snapshot' => 'Febi Bilstein Shock Absorber',
        ]);
    }

    // -------------------------------------------------------------------------
    // Delivery Note
    // -------------------------------------------------------------------------

    #[Test]
    public function creating_delivery_note_line_with_product_captures_designation_snapshot(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Castrol Edge 5W30',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/delivery-notes', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Castrol Edge 5W30',
                        'quantity' => 4,
                        'unit_price' => 15.00,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $documentId = $response->json('data.id');

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'designation_default_snapshot' => 'Castrol Edge 5W30',
        ]);
    }

    // -------------------------------------------------------------------------
    // Return Note
    // -------------------------------------------------------------------------

    #[Test]
    public function creating_return_note_line_with_product_captures_designation_snapshot(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Hella Headlight Assembly',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Hella Headlight Assembly',
                        'quantity' => '1.00',
                        'unit_price' => '250.00',
                        'tax_rate' => '0.00',
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $documentId = $response->json('data.id');

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'designation_default_snapshot' => 'Hella Headlight Assembly',
        ]);
    }

    #[Test]
    public function updating_return_note_lines_captures_designation_snapshot(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Denso Starter Motor',
        ]);

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'description' => 'Generic electrical part',
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                        'tax_rate' => '0.00',
                    ],
                ],
            ]);

        $createResponse->assertStatus(201);
        $documentId = $createResponse->json('data.id');

        $updateResponse = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/return-notes/{$documentId}", [
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Denso Starter Motor',
                        'quantity' => '1.00',
                        'unit_price' => '175.00',
                        'tax_rate' => '0.00',
                    ],
                ],
            ]);

        $updateResponse->assertStatus(200);

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'designation_default_snapshot' => 'Denso Starter Motor',
        ]);
    }

    // -------------------------------------------------------------------------
    // CreditNoteService::createStandaloneCreditNote()
    // -------------------------------------------------------------------------

    #[Test]
    public function standalone_credit_note_line_with_product_captures_designation_snapshot(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'TRW Wheel Bearing',
        ]);

        /** @var CreditNoteService $service */
        $service = app(CreditNoteService::class);

        $creditNote = $service->createStandaloneCreditNote(
            partnerId: $this->partner->id,
            lines: [
                [
                    'product_id' => $product->id,
                    'description' => 'Customer compensation',
                    'quantity' => '1.0000',
                    'unit_price' => '45.00',
                    'tax_rate' => '0.00',
                ],
            ],
            reason: CreditNoteReason::OTHER,
        );

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $creditNote->id,
            'designation_default_snapshot' => 'TRW Wheel Bearing',
        ]);
    }
}
