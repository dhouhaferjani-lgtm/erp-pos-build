<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Listeners\ExitOnboardingOnFullCountFinalized;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Task C3 — onboarding lifecycle: auto-exit on qualifying finalized full/location
 * counts, and the negative-stock / no-stock-row worklist endpoint.
 */
final class OnboardingLifecycleTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Onboarding Lifecycle Tenant',
            'slug' => 'onboarding-lifecycle-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Onboarding Lifecycle Company',
            'legal_name' => 'Onboarding Lifecycle Company LLC',
            'tax_id' => 'OLC-TAX-'.uniqid(),
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
            'name' => 'Onboarding Lifecycle User',
            'email' => 'onboarding-lifecycle-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-OLC-'.uniqid(),
            'name' => 'Onboarding Lifecycle Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => true,
        ]);
    }

    private function makeProduct(string $sku): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '0.000000',
        ]);
    }

    private function makeCounting(CountingScopeType $scopeType, bool $includesZeroStock): InventoryCounting
    {
        return InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => $scopeType,
            'scope_filters' => [],
            'counting_number' => 'CNT-OLC-'.uniqid(),
            'status' => CountingStatus::Finalized,
            'ambiguity_window_minutes' => 15,
            'created_by_user_id' => $this->user->id,
            'includes_zero_stock' => $includesZeroStock,
        ]);
    }

    private function fireCompleted(InventoryCounting $counting): void
    {
        app(ExitOnboardingOnFullCountFinalized::class)->handle(new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->location->id,
            countingNumber: (string) $counting->counting_number,
            itemsCount: 1,
            totalVariance: '0.0000',
            completedBy: $this->user->id,
            completedAt: now()->toIso8601String(),
        ));
    }

    // --- Listener: auto-exit ---

    public function test_qualifying_full_count_finalize_flips_onboarding_mode_off(): void
    {
        $product = $this->makeProduct('OLC-001');
        $counting = $this->makeCounting(CountingScopeType::FullInventory, true);

        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
            'final_qty' => '5.0000',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

        $this->fireCompleted($counting);

        $this->assertFalse($this->location->fresh()->onboarding_mode);
    }

    public function test_qualifying_location_scoped_count_flips_onboarding_mode_off(): void
    {
        $product = $this->makeProduct('OLC-002');
        $counting = $this->makeCounting(CountingScopeType::Location, true);

        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
            'final_qty' => '5.0000',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

        $this->fireCompleted($counting);

        $this->assertFalse($this->location->fresh()->onboarding_mode);
    }

    public function test_zone_scoped_count_does_not_exit_onboarding(): void
    {
        $product = $this->makeProduct('OLC-003');
        $counting = $this->makeCounting(CountingScopeType::Zone, false);

        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
            'final_qty' => '5.0000',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

        $this->fireCompleted($counting);

        $this->assertTrue($this->location->fresh()->onboarding_mode);
    }

    public function test_full_count_without_includes_zero_stock_does_not_exit_onboarding(): void
    {
        $product = $this->makeProduct('OLC-004');
        $counting = $this->makeCounting(CountingScopeType::FullInventory, false);

        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
            'final_qty' => '5.0000',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

        $this->fireCompleted($counting);

        $this->assertTrue($this->location->fresh()->onboarding_mode);
    }

    // --- Worklist endpoint ---

    public function test_worklist_returns_negative_and_missing_stock_products_and_excludes_counted(): void
    {
        $negativeProduct = $this->makeProduct('OLC-NEG');
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $negativeProduct->id,
            'location_id' => $this->location->id,
            'quantity' => '-3.0000',
            'reserved' => '0.0000',
        ]);

        $missingProduct = $this->makeProduct('OLC-MISSING');
        // No stock_levels row at all for this product/location.

        $zeroProduct = $this->makeProduct('OLC-ZERO');
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $zeroProduct->id,
            'location_id' => $this->location->id,
            'quantity' => '0.0000',
            'reserved' => '0.0000',
        ]);

        $countedProduct = $this->makeProduct('OLC-COUNTED');
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $countedProduct->id,
            'location_id' => $this->location->id,
            'quantity' => '-1.0000',
            'reserved' => '0.0000',
        ]);
        $activeCounting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::ProductLocation,
            'scope_filters' => [],
            'counting_number' => 'CNT-OLC-COUNTED-'.uniqid(),
            'status' => CountingStatus::Count1InProgress,
            'ambiguity_window_minutes' => 15,
            'created_by_user_id' => $this->user->id,
        ]);
        InventoryCountingItem::create([
            'counting_id' => $activeCounting->id,
            'product_id' => $countedProduct->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '-1.0000',
            'count_1_qty' => '-1.0000',
            'count_1_at' => now(),
            'resolution_method' => ItemResolutionMethod::Pending,
        ]);

        $saleAt = CarbonImmutable::now()->subDays(3);
        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $negativeProduct->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::POSSale,
            'quantity' => '1.0000',
            'quantity_before' => '-2.0000',
            'quantity_after' => '-3.0000',
            'occurred_at' => $saleAt,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/onboarding-worklist?location_id='.$this->location->id);

        $response->assertOk();

        $productIds = collect($response->json('data'))->pluck('product_id')->all();

        $this->assertContains($negativeProduct->id, $productIds);
        $this->assertContains($missingProduct->id, $productIds);
        $this->assertNotContains($zeroProduct->id, $productIds);
        $this->assertNotContains($countedProduct->id, $productIds);

        $negativeRow = collect($response->json('data'))->firstWhere('product_id', $negativeProduct->id);
        $this->assertSame('-3.0000', $negativeRow['on_hand']);
        $this->assertNotNull($negativeRow['last_sold_at']);
        $this->assertSame($saleAt->toIso8601String(), $negativeRow['last_sold_at']);
        $this->assertSame('OLC-NEG', $negativeRow['sku']);

        $missingRow = collect($response->json('data'))->firstWhere('product_id', $missingProduct->id);
        $this->assertNull($missingRow['last_sold_at']);
    }

    public function test_worklist_excludes_soft_deleted_product_with_negative_stock(): void
    {
        $deletedProduct = $this->makeProduct('OLC-DELETED');
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $deletedProduct->id,
            'location_id' => $this->location->id,
            'quantity' => '-2.0000',
            'reserved' => '0.0000',
        ]);
        $deletedProduct->delete();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/onboarding-worklist?location_id='.$this->location->id);

        $response->assertOk();

        $productIds = collect($response->json('data'))->pluck('product_id')->all();

        $this->assertNotContains($deletedProduct->id, $productIds);
    }

    public function test_worklist_includes_generated_but_uncounted_item(): void
    {
        $uncountedProduct = $this->makeProduct('OLC-UNCOUNTED');
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $uncountedProduct->id,
            'location_id' => $this->location->id,
            'quantity' => '-4.0000',
            'reserved' => '0.0000',
        ]);

        $activeCounting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::ProductLocation,
            'scope_filters' => [],
            'counting_number' => 'CNT-OLC-UNCOUNTED-'.uniqid(),
            'status' => CountingStatus::Count1InProgress,
            'ambiguity_window_minutes' => 15,
            'created_by_user_id' => $this->user->id,
        ]);
        InventoryCountingItem::create([
            'counting_id' => $activeCounting->id,
            'product_id' => $uncountedProduct->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '-4.0000',
            'count_1_qty' => null,
            'count_2_qty' => null,
            'count_3_qty' => null,
            'resolution_method' => ItemResolutionMethod::Pending,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/onboarding-worklist?location_id='.$this->location->id);

        $response->assertOk();

        $productIds = collect($response->json('data'))->pluck('product_id')->all();

        $this->assertContains($uncountedProduct->id, $productIds);
    }

    public function test_worklist_returns_422_on_malformed_location_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/onboarding-worklist?location_id=not-a-uuid');

        $this->assertApiValidationErrors($response, ['location_id']);
    }
}
