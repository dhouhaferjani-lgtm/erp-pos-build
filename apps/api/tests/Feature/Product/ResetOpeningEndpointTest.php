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
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 9 — integration tests for POST /api/v1/products/{product}/opening/reset.
 *
 * Scenarios:
 *   1. Product with a sole opening → 200 + data.opening.can_enter_opening = true.
 *   2. Product with opening + downstream movement → 409.
 *   3. User without inventory.adjust → 403.
 */
final class ResetOpeningEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $defaultLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Reset Opening Tenant',
            'slug' => 'reset-opening-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reset Opening Company',
            'legal_name' => 'Reset Opening Company LLC',
            'tax_id' => 'TAX-RO-001',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // GL accounts required by OpeningBalancePostingService + ResetOpeningBalanceService.
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
            'email' => "user-{$counter}@reset-opening-test.example.com",
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
     * Create a physical product that has an active opening balance stock movement.
     */
    private function createProductWithOpening(): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => "Product With Opening {$counter}",
            'sku' => "RO-SKU-{$counter}",
            'is_physical' => true,
            'cost_price' => '5.000000',
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
            'unit_cost' => '5.000000',
            'total_cost' => '35.000000',
            'is_historical' => true,
        ]);

        return $product;
    }

    /**
     * Create a physical product with an active opening and a downstream (non-opening)
     * movement. Resetting such a product must be blocked with 409.
     */
    private function createProductWithOpeningAndDownstream(): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => "Product With Downstream {$counter}",
            'sku' => "ROD-SKU-{$counter}",
            'is_physical' => true,
            'cost_price' => '5.000000',
        ]);

        // Active opening.
        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->defaultLocation->id,
            'movement_type' => MovementType::Opening,
            'quantity' => '7.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '7.0000',
            'unit_cost' => '5.000000',
            'total_cost' => '35.000000',
            'is_historical' => true,
        ]);

        // Downstream adjustment — blocks the reset.
        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->defaultLocation->id,
            'movement_type' => MovementType::Adjustment,
            'quantity' => '2.0000',
            'quantity_before' => '7.0000',
            'quantity_after' => '9.0000',
        ]);

        return $product;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * Happy path: product with a sole opening balance → reset succeeds,
     * response has can_enter_opening = true.
     */
    public function test_reset_sole_opening_returns_200_and_can_enter_opening(): void
    {
        $this->actingAsUserWith(['products.view', 'inventory.adjust']);
        $product = $this->createProductWithOpening();

        $resp = $this->postJson("/api/v1/products/{$product->id}/opening/reset");

        $resp->assertOk();
        $resp->assertJsonPath('data.opening.can_enter_opening', true);
        $resp->assertJsonPath('data.opening.has_active_opening', false);
    }

    /**
     * Product with opening + downstream movement → 409 (reset blocked).
     */
    public function test_reset_blocked_by_downstream_movement_returns_409(): void
    {
        $this->actingAsUserWith(['products.view', 'inventory.adjust']);
        $product = $this->createProductWithOpeningAndDownstream();

        $this->postJson("/api/v1/products/{$product->id}/opening/reset")
            ->assertStatus(409);
    }

    /**
     * User without inventory.adjust permission → 403.
     */
    public function test_reset_requires_inventory_adjust_permission(): void
    {
        $this->actingAsUserWith(['products.view']);
        $product = $this->createProductWithOpening();

        $this->postJson("/api/v1/products/{$product->id}/opening/reset")
            ->assertForbidden();
    }
}
