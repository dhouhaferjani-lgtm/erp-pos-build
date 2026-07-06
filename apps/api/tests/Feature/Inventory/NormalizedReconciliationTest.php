<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

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
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task B4 — multi-counter normalization: a raw disagreement between two
 * blind counts taken at different instants can be a genuine agreement once
 * both counts are normalized (via B1's MovementReplayService) to a common
 * instant — the later of the two `count_N_at_estimate` timestamps (B2).
 *
 * Normative rule (task-B4-brief.md):
 *   normalized_N = bcadd(count_N_qty, signedDelta(product, location, variant,
 *       count_N_at_estimate, T_latest), InventoryScale::QUANTITY_SCALE)
 *
 * Raw-disagree-but-normalized-agree resolves the item (final_qty = the
 * normalized value) and appends the INFORMATIONAL `normalized_agreement`
 * flag reason — it never itself sets `is_flagged`. Single-count sessions
 * and legacy items (no `count_N_at_estimate`) are untouched.
 */
final class NormalizedReconciliationTest extends TestCase
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

        $this->service = $this->app->make(CountingReconciliationService::class);

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

    public function test_raw_disagreement_normalizes_to_agreement_with_intervening_sale(): void
    {
        $count1At = CarbonImmutable::parse('2026-07-01 10:00:00');
        $saleAt = CarbonImmutable::parse('2026-07-01 11:00:00');
        $count2At = CarbonImmutable::parse('2026-07-01 12:00:00');

        // Sale of 2 between the two counts: stock goes 10 -> 8.
        $this->movement('10.0000', '8.0000', $saleAt);

        $item = $this->createTestItem([
            'theoretical_qty' => '8.0000',
            'count_1_qty' => '10.0000',
            'count_1_at_estimate' => $count1At,
            'count_2_qty' => '8.0000',
            'count_2_at_estimate' => $count2At,
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('8.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoAllMatch, $item->resolution_method);
        $this->assertFalse($item->is_flagged, 'normalized_agreement must never itself set is_flagged');
        $this->assertContains('normalized_agreement', $item->flag_reasons ?? []);
        $this->assertNotNull($item->resolved_at);
    }

    public function test_raw_disagreement_normalizes_to_agreement_but_still_varies_from_theoretical(): void
    {
        $count1At = CarbonImmutable::parse('2026-07-01 10:00:00');
        $saleAt = CarbonImmutable::parse('2026-07-01 11:00:00');
        $count2At = CarbonImmutable::parse('2026-07-01 12:00:00');

        // Sale of 2 between the two counts: stock goes 10 -> 8.
        $this->movement('10.0000', '8.0000', $saleAt);

        // Theoretical (stale book qty) disagrees with the normalized physical count.
        $item = $this->createTestItem([
            'theoretical_qty' => '50.0000',
            'count_1_qty' => '10.0000',
            'count_1_at_estimate' => $count1At,
            'count_2_qty' => '8.0000',
            'count_2_at_estimate' => $count2At,
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('8.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoCountersAgree, $item->resolution_method);
        $this->assertTrue($item->is_flagged, 'is_flagged here comes from the theoretical variance, not from normalized_agreement');
        $this->assertEquals('critical_variance', $item->flag_reason);
        $this->assertContains('normalized_agreement', $item->flag_reasons ?? []);
    }

    public function test_same_counts_without_intervening_sale_stays_genuine_mismatch(): void
    {
        $count1At = CarbonImmutable::parse('2026-07-01 10:00:00');
        $count2At = CarbonImmutable::parse('2026-07-01 12:00:00');

        // No movement between the two counts: normalization is a no-op,
        // so the raw disagreement must be handled exactly as today.
        $item = $this->createTestItem([
            'theoretical_qty' => '100.0000',
            'count_1_qty' => '10.0000',
            'count_1_at_estimate' => $count1At,
            'count_2_qty' => '8.0000',
            'count_2_at_estimate' => $count2At,
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertNull($item->final_qty);
        $this->assertEquals(ItemResolutionMethod::Pending, $item->resolution_method);
        $this->assertTrue($item->is_flagged);
        $this->assertEquals('counter_disagreement', $item->flag_reason);
        $this->assertEmpty($item->flag_reasons ?? []);
    }

    public function test_legacy_items_without_estimates_use_raw_comparison_even_with_intervening_sale(): void
    {
        $saleAt = CarbonImmutable::parse('2026-07-01 11:00:00');

        // Same sale as the agreement scenario, but count_N_at_estimate is
        // null (pre-B2 legacy data) - normalization must be skipped entirely.
        $this->movement('10.0000', '8.0000', $saleAt);

        $item = $this->createTestItem([
            'theoretical_qty' => '8.0000',
            'count_1_qty' => '10.0000',
            'count_2_qty' => '8.0000',
        ]);

        $this->assertNull($item->count_1_at_estimate);
        $this->assertNull($item->count_2_at_estimate);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertNull($item->final_qty);
        $this->assertEquals(ItemResolutionMethod::Pending, $item->resolution_method);
        $this->assertTrue($item->is_flagged);
        $this->assertEquals('counter_disagreement_one_matches_theoretical', $item->flag_reason);
        $this->assertEmpty($item->flag_reasons ?? []);
    }

    public function test_single_count_session_is_unaffected_by_normalization(): void
    {
        $item = $this->createTestItem([
            'theoretical_qty' => '50.0000',
            'count_1_qty' => '48.0000',
            'count_1_at_estimate' => CarbonImmutable::parse('2026-07-01 10:00:00'),
        ]);

        $this->service->reconcileItem($item);
        $item->refresh();

        $this->assertEquals('48.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoCountersAgree, $item->resolution_method);
        $this->assertTrue($item->is_flagged);
        $this->assertEquals('minor_variance', $item->flag_reason);
        $this->assertEmpty($item->flag_reasons ?? []);
    }

    /**
     * Creates a stock_movements row with explicit before/after quantities and
     * event time, bypassing the higher-level services so the test controls
     * the exact signed delta and occurred_at independently of movement_type.
     */
    private function movement(string $quantityBefore, string $quantityAfter, CarbonImmutable $occurredAt): StockMovement
    {
        return StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::POSSale,
            'quantity' => bcsub($quantityAfter, $quantityBefore, 4),
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'occurred_at' => $occurredAt,
        ]);
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
            'requires_count_2' => true,
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
