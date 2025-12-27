<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\CountingReconciliationService;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for CountingReconciliationService.
 */
class CountingReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private CountingReconciliationService $service;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CountingReconciliationService;

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

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
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

    public function test_single_count_matches_theoretical_auto_resolves(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '50.0000',
            'count_1_qty' => '50.0000',
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('50.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoAllMatch, $item->resolution_method);
        $this->assertFalse($item->is_flagged);
    }

    public function test_single_count_differs_from_theoretical_flags_variance(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '50.0000',
            'count_1_qty' => '48.0000',
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('48.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoCountersAgree, $item->resolution_method);
        $this->assertTrue($item->is_flagged);
    }

    public function test_double_count_both_match_theoretical_auto_resolves(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '50.0000',
            'count_1_qty' => '50.0000',
            'count_2_qty' => '50.0000',
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('50.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoAllMatch, $item->resolution_method);
        $this->assertFalse($item->is_flagged);
    }

    public function test_double_count_counters_agree_but_differ_from_theoretical(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '50.0000',
            'count_1_qty' => '48.0000',
            'count_2_qty' => '48.0000',
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('48.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoCountersAgree, $item->resolution_method);
        $this->assertTrue($item->is_flagged);
        $this->assertEquals('variance_from_theoretical', $item->flag_reason);
    }

    public function test_double_count_counters_disagree_flags_for_third_count(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '50.0000',
            'count_1_qty' => '48.0000',
            'count_2_qty' => '52.0000',
        ]);

        $this->service->reconcileItem($item, hasThirdCount: true);
        $item->refresh();

        $this->assertNull($item->final_qty);
        $this->assertEquals(ItemResolutionMethod::Pending, $item->resolution_method);
        $this->assertTrue($item->is_flagged);
        $this->assertEquals('counter_disagreement', $item->flag_reason);
    }

    public function test_triple_count_majority_resolves(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '50.0000',
            'count_1_qty' => '50.0000',
            'count_2_qty' => '48.0000',
            'count_3_qty' => '50.0000',
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('50.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::ThirdCountDecisive, $item->resolution_method);
        $this->assertTrue($item->is_flagged);
        $this->assertNotNull($item->flag_reason);
        $this->assertStringContainsString('counter_2', $item->flag_reason);
    }

    public function test_triple_count_no_consensus_stays_pending(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '50.0000',
            'count_1_qty' => '48.0000',
            'count_2_qty' => '52.0000',
            'count_3_qty' => '55.0000',
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertNull($item->final_qty);
        $this->assertTrue($item->is_flagged);
        $this->assertEquals('no_consensus', $item->flag_reason);
    }

    public function test_already_resolved_item_is_skipped(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '50.0000',
            'count_1_qty' => '50.0000',
            'resolution_method' => ItemResolutionMethod::ManualOverride,
            'final_qty' => '55.0000',
        ]);

        $originalFinalQty = $item->final_qty;
        $this->service->reconcileItem($item);
        $item->refresh();

        // Item should not be modified
        $this->assertEquals($originalFinalQty, $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::ManualOverride, $item->resolution_method);
    }

    public function test_critical_variance_flag_reason(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '100.0000',
            'count_1_qty' => '85.0000', // 15% variance (critical)
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('critical_variance', $item->flag_reason);
    }

    public function test_significant_variance_flag_reason(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '100.0000',
            'count_1_qty' => '93.0000', // 7% variance (significant)
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('significant_variance', $item->flag_reason);
    }

    public function test_minor_variance_flag_reason(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '100.0000',
            'count_1_qty' => '97.0000', // 3% variance (minor)
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('minor_variance', $item->flag_reason);
    }

    /**
     * Create a test counting item.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createTestItem(array $attributes): InventoryCountingItem
    {
        $counting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'execution_mode' => CountingExecutionMode::Sequential,
            'status' => CountingStatus::Count1InProgress,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
        ]);

        $defaults = [
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'resolution_method' => ItemResolutionMethod::Pending,
        ];

        return InventoryCountingItem::create(array_merge($defaults, $attributes));
    }
}
