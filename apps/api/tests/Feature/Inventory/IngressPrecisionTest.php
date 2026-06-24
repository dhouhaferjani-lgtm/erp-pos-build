<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Ingress precision regression coverage for StockMovementController.
 *
 * Phase 4.3 — validates that the decimal-ceiling regex (quantity/4) blocks
 * inputs whose scale exceeds decimal(15,4) while allowing up to 4 dp through.
 *
 * Covers: receive, issue, transfer, adjust endpoints.
 */
final class IngressPrecisionTest extends TestCase
{
    use AssertsApiValidation;
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
            'name' => 'Precision Inventory Tenant',
            'slug' => 'prec-inv-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Precision Inventory Co',
            'legal_name' => 'Precision Inventory Co LLC',
            'tax_id' => 'PRI123',
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
            'name' => 'Precision Inventory User',
            'email' => 'prec-inv@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'inventory.receive',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-PREC',
            'name' => 'Precision Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PREC-001',
            'name' => 'Precision Part',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // /receive — quantity must be ≤ 4 decimal places, strictly positive
    // -------------------------------------------------------------------------

    public function test_receive_accepts_quantity_with_four_decimal_places(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/receive', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '12.1234',
                'reference' => 'PO-PREC-001',
            ]);

        $response->assertStatus(201);
    }

    public function test_receive_accepts_integer_quantity(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/receive', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '5',
                'reference' => 'PO-PREC-002',
            ]);

        $response->assertStatus(201);
    }

    public function test_receive_rejects_quantity_with_five_decimal_places(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/receive', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '12.12345',
                'reference' => 'PO-PREC-003',
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['quantity']);
    }

    public function test_receive_rejects_quantity_with_eight_decimal_places(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/receive', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '1.00000001',
                'reference' => 'PO-PREC-004',
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['quantity']);
    }

    // -------------------------------------------------------------------------
    // /issue — quantity must be ≤ 4 decimal places, strictly positive
    // -------------------------------------------------------------------------

    public function test_issue_rejects_quantity_with_five_decimal_places(): void
    {
        // First put some stock in
        $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/receive', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '50',
                'reference' => 'PO-001',
            ])->assertStatus(201);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/issue', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '3.12345',
                'reference' => 'SO-001',
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['quantity']);
    }

    public function test_issue_accepts_quantity_with_four_decimal_places(): void
    {
        // Receive first
        $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/receive', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '50',
                'reference' => 'PO-002',
            ])->assertStatus(201);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/issue', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '3.1234',
                'reference' => 'SO-002',
            ]);

        $response->assertStatus(201);
    }

    // -------------------------------------------------------------------------
    // /transfer — quantity must be ≤ 4 decimal places
    // -------------------------------------------------------------------------

    public function test_transfer_rejects_quantity_with_five_decimal_places(): void
    {
        $secondWarehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-02',
            'name' => 'Secondary Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        // Receive stock first
        $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/receive', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '100',
                'reference' => 'PO-003',
            ])->assertStatus(201);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/transfer', [
                'product_id' => $this->product->id,
                'from_location_id' => $this->warehouse->id,
                'to_location_id' => $secondWarehouse->id,
                'quantity' => '5.12345',
                'reference' => 'TR-001',
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['quantity']);
    }

    public function test_transfer_accepts_quantity_with_four_decimal_places(): void
    {
        $secondWarehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-03',
            'name' => 'Tertiary Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        // Receive stock first
        $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/receive', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '100',
                'reference' => 'PO-004',
            ])->assertStatus(201);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/transfer', [
                'product_id' => $this->product->id,
                'from_location_id' => $this->warehouse->id,
                'to_location_id' => $secondWarehouse->id,
                'quantity' => '5.1234',
                'reference' => 'TR-002',
            ]);

        $response->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // /adjust — new_quantity must be ≤ 4 decimal places (min:0 — zero allowed for full write-off)
    // -------------------------------------------------------------------------

    public function test_adjust_accepts_new_quantity_with_four_decimal_places(): void
    {
        // Receive stock first
        $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/receive', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '50',
                'reference' => 'PO-005',
            ])->assertStatus(201);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/adjust', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'new_quantity' => '45.1234',
                'reason_code' => 'adjustment_negative',
                'reason' => 'Physical count — sub-unit adjustment',
            ]);

        $response->assertStatus(201);
    }

    public function test_adjust_rejects_new_quantity_with_five_decimal_places(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/adjust', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'new_quantity' => '45.12345',
                'reason' => 'Count',
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['new_quantity']);
    }

    public function test_adjust_accepts_zero_new_quantity_for_full_writeoff(): void
    {
        // Receive stock first
        $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/receive', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'quantity' => '10',
                'reference' => 'PO-006',
            ])->assertStatus(201);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/stock-movements/adjust', [
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'new_quantity' => '0',
                'reason_code' => 'write_off',
                'reason' => 'All units damaged',
            ]);

        $response->assertStatus(201);
    }

    // -------------------------------------------------------------------------
    // Fast Validator::make boundary checks.
    //
    // NOTE: these MIRROR (do NOT bind to) the inline validators in
    // StockMovementController (app/Modules/Inventory/Presentation/Controllers/
    // StockMovementController.php :65 quantity, :222 new_quantity). The
    // production binding is provided by the HTTP 422 tests above; these are only
    // extra boundary-case coverage.
    // -------------------------------------------------------------------------

    public function test_quantity_regex_rules_via_validator(): void
    {
        // Mirrors StockMovementController.php:65 — NOT bound to production.
        $rules = [
            'quantity' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];

        $validValues = ['1', '1.0', '1.12', '1.123', '1.1234', '999.9999'];
        foreach ($validValues as $val) {
            $v = Validator::make(['quantity' => $val], $rules);
            $this->assertFalse($v->fails(), "Expected valid: $val");
        }

        $invalidValues = ['1.12345', '0.000001', '1.000001'];
        foreach ($invalidValues as $val) {
            $v = Validator::make(['quantity' => $val], $rules);
            $this->assertTrue($v->fails(), "Expected invalid: $val");
        }
    }

    public function test_new_quantity_zero_is_valid_in_adjust(): void
    {
        // Mirrors StockMovementController.php:222 — NOT bound to production.
        $rules = [
            'new_quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];

        $v = Validator::make(['new_quantity' => '0'], $rules);
        $this->assertFalse($v->fails(), 'Zero new_quantity is valid (full write-off)');

        $v2 = Validator::make(['new_quantity' => '0.12345'], $rules);
        $this->assertTrue($v2->fails(), '5-decimal new_quantity must fail');
    }
}
