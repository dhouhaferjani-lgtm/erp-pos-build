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
use App\Modules\Product\Domain\Events\ProductCreated;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 7 — integration tests for the inline opening-balance posting wired into
 * ProductController::store(). Four scenarios:
 *   1. Happy path: physical product + both opening fields → 201 + movement posted.
 *   2. Authz: user without inventory.adjust → 403.
 *   3. Non-physical (service) product + opening fields → 422.
 *   4. No location configured → 422 + full rollback (zero products, no event).
 */
class CreateProductWithOpeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Opening Test Tenant',
            'slug' => 'opening-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Test Company',
            'legal_name' => 'Opening Test Company LLC',
            'tax_id' => 'TAX-OT-001',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND', // scale-3 currency so '5.000' passes OpeningBalanceLine validation
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

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Minimal valid product payload with overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validProductPayload(array $overrides = []): array
    {
        static $counter = 0;
        $counter++;

        return array_merge([
            'name' => "Opening Product {$counter}",
            'sku' => "OP-SKU-{$counter}",
        ], $overrides);
    }

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
            'email' => "user-{$counter}@opening-test.example.com",
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
     * Create the default active location for the company.
     */
    private function createDefaultLocation(): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * Happy path: physical product with opening qty and unit cost.
     * Expects 201, opening state flags in response, and exactly one
     * Opening stock movement in the DB.
     */
    public function test_create_with_opening_posts_one_movement_and_locks(): void
    {
        $this->createDefaultLocation();

        $resp = $this->actingAsUserWith(['products.create', 'inventory.adjust'])
            ->postJson('/api/v1/products', $this->validProductPayload([
                'is_physical' => true,
                'opening_qty' => '10',
                'opening_unit_cost' => '5.000',
            ]));

        $resp->assertCreated();
        $resp->assertJsonPath('data.opening.has_active_opening', true);
        $resp->assertJsonPath('data.opening.can_enter_opening', false);

        /** @var string $productId */
        $productId = $resp->json('data.id');
        $this->assertIsString($productId);

        $this->assertSame(
            1,
            StockMovement::where('product_id', $productId)
                ->where('movement_type', MovementType::Opening)
                ->count(),
        );
    }

    /**
     * A user with products.create but without inventory.adjust must receive 403
     * when they supply opening balance fields with a positive quantity.
     */
    public function test_create_with_opening_requires_inventory_adjust(): void
    {
        $this->createDefaultLocation();

        $resp = $this->actingAsUserWith(['products.create']) // NO inventory.adjust
            ->postJson('/api/v1/products', $this->validProductPayload([
                'is_physical' => true,
                'opening_qty' => '10',
                'opening_unit_cost' => '5.000',
            ]));

        $resp->assertForbidden();
    }

    /**
     * A non-physical (service) product cannot carry an opening stock balance.
     * Expect a 422 error when opening fields are supplied for a service type.
     */
    public function test_opening_rejected_on_service_product(): void
    {
        $this->createDefaultLocation();

        $resp = $this->actingAsUserWith(['products.create', 'inventory.adjust'])
            ->postJson('/api/v1/products', $this->validProductPayload([
                'type' => 'service',
                'is_physical' => false,
                'opening_qty' => '10',
                'opening_unit_cost' => '5.000',
            ]));

        $resp->assertStatus(422);
    }

    /**
     * When the opening balance posting fails (no default location configured),
     * the whole DB::transaction must roll back — zero products persisted — and
     * no ProductCreated event must be dispatched.
     *
     * Note: DB::afterCommit callbacks do not fire under RefreshDatabase (the outer
     * test transaction is rolled back, never committed), so Event::assertNotDispatched
     * here also verifies that the event was not eagerly dispatched before the rollback.
     */
    public function test_product_rolled_back_and_no_event_when_opening_fails(): void
    {
        Event::fake([ProductCreated::class]);

        // No location created → getDefaultLocation returns null → abort(422) → rollback.
        $resp = $this->actingAsUserWith(['products.create', 'inventory.adjust'])
            ->postJson('/api/v1/products', $this->validProductPayload([
                'is_physical' => true,
                'opening_qty' => '10',
                'opening_unit_cost' => '5.000',
            ]));

        $resp->assertStatus(422);
        $this->assertSame(0, Product::count());
        Event::assertNotDispatched(ProductCreated::class);
    }
}
