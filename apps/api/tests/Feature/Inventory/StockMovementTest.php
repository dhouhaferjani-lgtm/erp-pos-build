<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

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
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
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
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
    }

    public function test_can_list_stock_movements(): void
    {
        $service = app(StockAdjustmentService::class);

        // Create multiple movements
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '25.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_stock_movement_list_exposes_product_unit_quantity_decimals(): void
    {
        $category = UnitCategory::factory()->create([
            'tenant_id' => null,
            'code' => 'stock-movement-weight',
            'name' => 'Stock Movement Weight',
            'is_system' => true,
            'is_active' => true,
        ]);
        $unit = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'stock-movement-kg',
            'name' => 'Stock Movement Kilogram',
            'symbol' => 'kg',
            'decimal_places' => 3,
            'is_system' => true,
            'is_active' => true,
        ]);
        $this->product->update(['unit_id' => $unit->id]);
        app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '2.5000',
            reference: 'UNIT-PRECISION',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements');

        $response->assertOk();
        $response->assertJsonPath('data.0.quantity', '2.5000');
        $response->assertJsonPath('data.0.quantity_decimals', 3);
    }

    public function test_can_filter_movements_by_product(): void
    {
        $product2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-002',
            'name' => 'Second Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        $service = app(StockAdjustmentService::class);

        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '50.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $service->receive(
            productId: $product2->id,
            locationId: $this->warehouse->id,
            quantity: '30.00',
            reference: 'PO-002',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements?product_id='.$this->product->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_filter_movements_by_type(): void
    {
        $service = app(StockAdjustmentService::class);

        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );
        $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '15.00',
            reference: 'SO-002',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements?movement_type=issue');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_list_exposes_source_document_provenance(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2026-0001',
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'discount_amount' => '0.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '100.000',
        ]);

        $movement = app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: $document->document_number,
            userId: $this->user->id,
        );
        $movement->forceFill([
            'reference_type' => 'Document',
            'reference_id' => $document->id,
        ])->save();

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements?product_id='.$this->product->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.source_document_id', $document->id);
        $response->assertJsonPath('data.0.source_document_type', 'invoice');
    }

    public function test_movements_are_ordered_by_date_descending(): void
    {
        $service = app(StockAdjustmentService::class);

        $receiptMovement = $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        // Wait 1 second to ensure different timestamps
        sleep(1);

        $issueMovement = $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements');

        $response->assertStatus(200);
        // Most recent (issue) should be first
        $response->assertJsonPath('data.0.movement_type', 'issue');
        $response->assertJsonPath('data.1.movement_type', 'receipt');
    }

    /**
     * DPA V7 / T11 — the four raw write endpoints are GONE.
     *
     * A 404 (no route) rather than a 403, because the routes themselves were
     * deleted. Their coverage did not evaporate: the precision boundary moved to
     * IngressPrecisionTest, the reserved-aware refusal below and in
     * StockAdjustByDeltaTest, and the permission matrix to
     * StockAdjustmentEndpointTest.
     */
    public function test_the_four_raw_stock_write_endpoints_no_longer_exist(): void
    {
        foreach ([
            '/api/v1/stock-movements/receive',
            '/api/v1/stock-movements/issue',
            '/api/v1/stock-movements/transfer',
            '/api/v1/stock-movements/adjust',
        ] as $route) {
            $this->actingAs($this->user)->postJson($route, [])->assertNotFound();
        }

        // The READ surface is untouched.
        $this->actingAs($this->user)->getJson('/api/v1/stock-movements')->assertOk();
    }

    /**
     * The replacement for test_issue_fails_with_insufficient_stock.
     *
     * It maps onto the RESERVED-AWARE boundary, not the on-hand one (D1a /
     * gate I-1): `issue()` refused against `quantity - reserved`, so the
     * replacement MUST exercise `reserved > 0` or the loosening would ship
     * green — 10 on hand with 8 reserved leaves only 2 available.
     */
    public function test_a_negative_adjustment_is_refused_at_the_reserved_aware_boundary(): void
    {
        $this->user->givePermissionTo([
            'inventory.adjustments.view',
            'inventory.adjustments.create',
            'inventory.adjustments.post',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '10.0000',
            'reserved' => '8.0000',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'post_immediately' => true,
            'lines' => [[
                'product_id' => $this->product->id,
                'reason_code' => 'adjustment_negative',
                'delta_quantity' => '-5.0000',
                'observed_before' => '10.0000',
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ADJUSTMENT_EXCEEDS_AVAILABLE');
        // On hand would have allowed −5; AVAILABLE does not.
        $response->assertJsonPath('error.details.quantity_before', '10.0000');
        $response->assertJsonPath('error.details.reserved', '8.0000');
        $response->assertJsonPath('error.details.available', '2.0000');
        $response->assertJsonPath('error.details.overridable', true);

        $this->assertSame('10.0000', (string) StockLevel::query()
            ->where('product_id', $this->product->id)
            ->value('quantity'));
    }

    /**
     * Retargeted by T11: the raw receive endpoint it guarded is deleted, so the
     * authorization boundary it protected is now the ADJUSTMENT document's. The
     * full matrix lives in StockAdjustmentEndpointTest; this keeps a direct
     * assertion in the file that used to own it.
     */
    public function test_unauthorized_user_cannot_write_stock(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'reason_code' => 'adjustment_positive',
                'delta_quantity' => '10.0000',
                'observed_before' => '0.0000',
            ]],
        ]);

        // The user holds inventory.adjust but NOT inventory.adjustments.create.
        $response->assertStatus(403);
    }
}
