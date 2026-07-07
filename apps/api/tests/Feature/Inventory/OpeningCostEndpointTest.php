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
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task D3 — opening-cost backfill endpoint + the listener gap fix that treats a
 * non-positive `products.cost_price` as a MISSING opening cost (so onboarding
 * openings never silently post at zero cost).
 *
 * Endpoint: PATCH /inventory/countings/{id}/items/{itemId}/opening-cost.
 */
final class OpeningCostEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'OC Tenant',
            'slug' => 'oc-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'OC Company',
            'legal_name' => 'OC Company LLC',
            'tax_id' => 'OC-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'OC Admin',
            'email' => 'oc-admin-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->admin->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-OC-'.uniqid(),
            'name' => 'OC Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'OC-'.uniqid(),
            'name' => 'OC Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '0.000000',
        ]);
    }

    private function counting(CountingStatus $status = CountingStatus::PendingReview): InventoryCounting
    {
        return InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'counting_number' => 'CNT-OC-'.uniqid(),
            'status' => $status,
            'ambiguity_window_minutes' => 15,
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(InventoryCounting $counting, array $attributes = []): InventoryCountingItem
    {
        return InventoryCountingItem::create(array_merge([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '0.0000',
            'final_qty' => '10.0000',
            'final_qty_as_of' => CarbonImmutable::now()->subHours(2),
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ], $attributes));
    }

    private function fire(InventoryCounting $counting): void
    {
        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->location->id,
            countingNumber: (string) $counting->counting_number,
            itemsCount: 1,
            totalVariance: '0.0000',
            completedBy: $this->admin->id,
            completedAt: now()->toIso8601String(),
        ));
    }

    // ================= endpoint =================

    public function test_patch_writes_opening_unit_cost(): void
    {
        $counting = $this->counting();
        $item = $this->item($counting);

        $response = $this->actingAs($this->admin)->patchJson(
            "/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/opening-cost",
            ['unit_cost' => '3.5'],
        );

        $response->assertStatus(200);
        $item->refresh();
        $this->assertSame('3.500000', $item->opening_unit_cost);
    }

    public function test_malformed_cost_returns_422(): void
    {
        $counting = $this->counting();
        $item = $this->item($counting);

        $this->actingAs($this->admin)->patchJson(
            "/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/opening-cost",
            ['unit_cost' => '3.1234567'], // 7 fractional digits > 6 ceiling
        )->assertStatus(422);

        $this->actingAs($this->admin)->patchJson(
            "/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/opening-cost",
            ['unit_cost' => 'abc'],
        )->assertStatus(422);
    }

    public function test_malformed_uuid_route_param_is_rejected(): void
    {
        $counting = $this->counting();

        $this->actingAs($this->admin)->patchJson(
            "/api/v1/inventory/countings/{$counting->id}/items/not-a-uuid/opening-cost",
            ['unit_cost' => '3.5'],
        )->assertStatus(404);
    }

    public function test_finalized_and_posted_item_is_rejected_422(): void
    {
        $counting = $this->counting(CountingStatus::Finalized);
        // Posted: replay_audit recorded, not flagged.
        $item = $this->item($counting, [
            'replay_audit' => [
                'windowFrom' => CarbonImmutable::now()->subHour()->toIso8601String(),
                'windowTo' => now()->toIso8601String(),
                'replayedDelta' => '0.0000',
                'onHandAtApply' => '10.0000',
                'expectedAtApply' => '10.0000',
            ],
            'is_flagged' => false,
        ]);

        $this->actingAs($this->admin)->patchJson(
            "/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/opening-cost",
            ['unit_cost' => '3.5'],
        )->assertStatus(422);
    }

    public function test_finalized_but_flagged_pending_item_is_still_editable(): void
    {
        $counting = $this->counting(CountingStatus::Finalized);
        $item = $this->item($counting, [
            'flag_reasons' => [CountingItemFlagReason::PendingOpeningCost->value],
            'is_flagged' => true,
        ]);

        $this->actingAs($this->admin)->patchJson(
            "/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/opening-cost",
            ['unit_cost' => '4.25'],
        )->assertStatus(200);

        $item->refresh();
        $this->assertSame('4.250000', $item->opening_unit_cost);
    }

    // ================= listener gap fix =================

    public function test_zero_cost_price_is_treated_as_missing_and_flags_pending(): void
    {
        // cost_price = '0.000000' (default), no item-level override → cost missing.
        $counting = $this->counting(CountingStatus::Finalized);
        $item = $this->item($counting, ['opening_unit_cost' => null]);

        $this->fire($counting);

        $item->refresh();
        $this->assertContains(
            CountingItemFlagReason::PendingOpeningCost->value,
            $item->flag_reasons ?? [],
        );
        $this->assertTrue($item->is_flagged);

        // Nothing posted — no opening movement, stock untouched.
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Opening->value,
        ]);
        $this->assertNull(StockLevel::where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_explicit_endpoint_zero_cost_posts_at_zero(): void
    {
        // Deliberate zero-cost opening: the endpoint set opening_unit_cost = 0.
        $counting = $this->counting(CountingStatus::Finalized);
        $item = $this->item($counting, ['opening_unit_cost' => '0.000000']);

        $this->fire($counting);

        $item->refresh();
        $this->assertNotContains(
            CountingItemFlagReason::PendingOpeningCost->value,
            $item->flag_reasons ?? [],
        );

        // Opening posted at zero cost — movement exists, stock set.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Opening->value,
        ]);
        $this->assertSame('10.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_positive_cost_price_fallback_posts_opening(): void
    {
        $this->product->update(['cost_price' => '2.500000']);

        $counting = $this->counting(CountingStatus::Finalized);
        $item = $this->item($counting, ['opening_unit_cost' => null]);

        $this->fire($counting);

        $item->refresh();
        $this->assertNotContains(
            CountingItemFlagReason::PendingOpeningCost->value,
            $item->flag_reasons ?? [],
        );
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => MovementType::Opening->value,
        ]);
    }
}
