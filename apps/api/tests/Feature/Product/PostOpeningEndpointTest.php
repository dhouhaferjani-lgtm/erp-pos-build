<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 8 — integration tests for POST /api/v1/products/{product}/opening (re-entry).
 *
 * Scenarios:
 *   1. Eligible physical product (no opening, no downstream) → 201 + has_active_opening=true.
 *   2. Product with an active opening already → 409 (OpeningAlreadyExistsException path).
 *   3. Product with a downstream (non-opening) movement → 409 (abort_if path).
 *   4. User without inventory.adjust → 403.
 */
class PostOpeningEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $defaultLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Post Opening Tenant',
            'slug' => 'post-opening-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Post Opening Company',
            'legal_name' => 'Post Opening Company LLC',
            'tax_id' => 'TAX-PO-001',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND', // scale-3 so '3.000' passes OpeningBalanceLine validation
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // GL accounts required by OpeningBalancePostingService.
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3100',
            'name' => 'Inventory Asset',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3900',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => true,
        ]);

        $this->defaultLocation = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Create a user with the given direct permissions, create a company membership,
     * and return $this after actingAs().
     *
     * @param  array<string>  $permissions
     */
    private function actingAsUserWith(array $permissions): static
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => "Test User {$counter}",
            'email' => "user-{$counter}@post-opening-test.example.com",
            'password' => 'password',
            'status' => UserStatus::Active,
        ]);

        $user->givePermissionTo($permissions);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
        ]);

        return $this->actingAs($user, 'sanctum');
    }

    /**
     * Create a physical product with no stock movements (eligible for opening).
     */
    private function createPhysicalProductNoOpening(): Product
    {
        static $counter = 0;
        $counter++;

        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => "Physical Product {$counter}",
            'sku' => "PO-SKU-{$counter}",
            'is_physical' => true,
        ]);
    }

    /**
     * Create a physical product that already has an active Opening stock movement.
     * This causes OpeningAlreadyExistsException when postOpening() is called.
     */
    private function createProductWithOpening(): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => "Product With Opening {$counter}",
            'sku' => "POO-SKU-{$counter}",
            'is_physical' => true,
        ]);

        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->defaultLocation->id,
            'movement_type' => MovementType::Opening,
            'quantity' => '7.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '7.0000',
        ]);

        return $product;
    }

    /**
     * Create a physical product with a downstream (non-opening) movement.
     * This triggers the abort_if(hasDownstreamMovements) guard (409).
     */
    private function createProductWithDownstreamMovement(): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => "Product With Downstream {$counter}",
            'sku' => "POD-SKU-{$counter}",
            'is_physical' => true,
        ]);

        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->defaultLocation->id,
            'movement_type' => MovementType::Adjustment,
            'quantity' => '5.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '5.0000',
        ]);

        return $product;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * Happy path: eligible physical product, no prior opening, no downstream movements.
     * Expects 201 with opening.has_active_opening=true in the response data.
     */
    public function test_posts_opening_on_eligible_existing_product(): void
    {
        $this->actingAsUserWith(['products.create', 'inventory.adjust']);
        $product = $this->createPhysicalProductNoOpening();

        $resp = $this->postJson("/api/v1/products/{$product->id}/opening", [
            'opening_qty' => '7',
            'opening_unit_cost' => '3.000',
        ]);

        $resp->assertCreated()->assertJsonPath('data.opening.has_active_opening', true);
    }

    /**
     * When the product already has an active opening balance, the endpoint
     * must return 409 (OpeningAlreadyExistsException path).
     */
    public function test_rejects_when_active_opening_exists(): void
    {
        $this->actingAsUserWith(['products.create', 'inventory.adjust']);
        $product = $this->createProductWithOpening();

        $resp = $this->postJson("/api/v1/products/{$product->id}/opening", [
            'opening_qty' => '7',
            'opening_unit_cost' => '3.000',
        ]);

        $resp->assertStatus(409);
    }

    /**
     * When the product has a downstream (non-opening) movement, the endpoint
     * must return 409 (abort_if hasDownstreamMovements path).
     */
    public function test_rejects_when_product_has_downstream_movements(): void
    {
        $this->actingAsUserWith(['products.create', 'inventory.adjust']);
        $product = $this->createProductWithDownstreamMovement();

        $resp = $this->postJson("/api/v1/products/{$product->id}/opening", [
            'opening_qty' => '7',
            'opening_unit_cost' => '3.000',
        ]);

        $resp->assertStatus(409);
    }

    /**
     * A user without inventory.adjust must receive 403.
     */
    public function test_requires_inventory_adjust(): void
    {
        $this->actingAsUserWith(['products.view']);
        $product = $this->createPhysicalProductNoOpening();

        $this->postJson("/api/v1/products/{$product->id}/opening", [
            'opening_qty' => '7',
            'opening_unit_cost' => '3.000',
        ])->assertForbidden();
    }

    /**
     * A non-physical (Service) product must be rejected with 422 —
     * the abort_unless($model->is_physical, 422, ...) guard fires.
     */
    public function test_rejects_non_physical_product(): void
    {
        $this->actingAsUserWith(['products.create', 'inventory.adjust']);

        $serviceProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Service Product',
            'sku' => 'SVC-SKU-001',
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        $this->postJson("/api/v1/products/{$serviceProduct->id}/opening", [
            'opening_qty' => '5',
            'opening_unit_cost' => '10.000',
        ])->assertStatus(422);
    }

    /**
     * A zero opening_qty must be rejected with 422 — the withValidator
     * opening_qty_positive check fires. Proves Fix 1.
     */
    public function test_rejects_zero_opening_qty(): void
    {
        $this->actingAsUserWith(['products.create', 'inventory.adjust']);
        $product = $this->createPhysicalProductNoOpening();

        $this->postJson("/api/v1/products/{$product->id}/opening", [
            'opening_qty' => '0',
            'opening_unit_cost' => '3.000',
        ])->assertStatus(422);
    }
}
