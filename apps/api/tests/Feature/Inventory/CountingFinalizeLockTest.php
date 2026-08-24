<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Exceptions\CountingTransitionException;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingEvent;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Session B lane Q-2 / Inventory H-1 — counting `finalize()` took no row lock.
 *
 * Two concurrent finalizes both read `pending_review`, both passed
 * `canTransitionTo()`, both committed and both fired
 * `InventoryCountingCompleted`. This pins (a) the service-level lock +
 * re-assert and (b) the PostgreSQL partial-unique backstop that makes a second
 * counting apply fail at the database.
 */
final class CountingFinalizeLockTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    private InventoryCountingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Finalize Lock Tenant',
            'slug' => 'finalize-lock-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Finalize Lock Company',
            'legal_name' => 'Finalize Lock Company LLC',
            'tax_id' => 'FLK-TAX-'.uniqid(),
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
            'name' => 'Finalize Lock User',
            'email' => 'finalize-lock-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-FLK-'.uniqid(),
            'name' => 'Finalize Lock Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'FLK-'.uniqid(),
            'name' => 'Finalize Lock Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000000',
        ]);

        $this->service = app(InventoryCountingService::class);
    }

    /**
     * The double-click / client-retry interleave: request B loaded the counting
     * while it was still `pending_review`, request A finalized in between.
     * B's in-memory status is stale, so without a locked re-read inside the
     * transaction `canTransitionTo()` still passes and the counting finalizes
     * (and fires `InventoryCountingCompleted`) a second time.
     */
    public function test_second_finalize_on_a_stale_instance_is_refused(): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        $counting = $this->pendingReviewCounting();
        $this->resolvedItem($counting, theoretical: '10.0000', final: '12.0000');

        // Request B's handle, loaded BEFORE request A commits its finalize.
        $staleHandle = InventoryCounting::findOrFail($counting->id);

        // Request A wins.
        $this->service->finalize(InventoryCounting::findOrFail($counting->id), $this->user);
        $this->assertSame(CountingStatus::Finalized, $this->freshStatus($counting));

        try {
            $this->service->finalize($staleHandle, $this->user);
            $this->fail('Expected the second finalize to be refused by the locked re-read.');
        } catch (CountingTransitionException) {
            // expected
        }

        $this->assertSame(
            1,
            InventoryCountingEvent::query()
                ->where('counting_id', $counting->id)
                ->where('event_type', InventoryCountingEvent::COUNTING_FINALIZED)
                ->count(),
            'The counting must record exactly ONE finalize event.'
        );

        $this->assertSame(
            1,
            StockMovement::query()
                ->where('reference_type', StockMovementReferenceType::InventoryCounting->value)
                ->where('reference_id', $counting->id)
                ->count(),
            'Exactly ONE counting stock movement must exist for the counting.'
        );

        $this->assertSame(
            '12.0000',
            StockLevel::query()
                ->where('product_id', $this->product->id)
                ->where('location_id', $this->location->id)
                ->value('quantity'),
            'The count variance must have been applied exactly once.'
        );
    }

    /**
     * An illegal finalize (the counting is not in `pending_review`) is a
     * business-rule refusal, not a server fault: it must render as the module's
     * 422 envelope rather than the bare 500 `\InvalidArgumentException` produced.
     */
    public function test_illegal_finalize_transition_renders_422_not_500(): void
    {
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'counting_number' => 'CNT-'.substr(uniqid(), -8),
            'status' => CountingStatus::Count1InProgress,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
            'count_1_user_id' => $this->user->id,
        ]);
        $this->resolvedItem($counting, theoretical: '10.0000', final: '10.0000');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/finalize");

        $response->assertStatus(422);
        // Typed, not the generic BUSINESS_ERROR: the FE has to tell
        // "already finalized by someone else" apart from every other business
        // refusal, and it reads the pair from fields rather than the message.
        $response->assertJsonPath('error.code', CountingTransitionException::CODE);
        $response->assertJsonPath('error.current_status', CountingStatus::Count1InProgress->value);
        $response->assertJsonPath('error.attempted_status', CountingStatus::Finalized->value);
        $response->assertJsonPath('error.counting_id', $counting->id);
        $this->assertStringNotContainsString(
            $counting->id,
            (string) $response->json('error.message'),
            'The human-facing message must not lead with a bare UUID.'
        );

        $this->assertSame(CountingStatus::Count1InProgress, $this->freshStatus($counting));
    }

    /**
     * The DB backstop (H-1): a SECOND counting-apply movement for the same
     * (counting, product, location) grain must be rejected by PostgreSQL, so a
     * double apply cannot succeed even if every service-level guard is bypassed.
     */
    public function test_duplicate_counting_apply_movement_is_rejected_null_variant(): void
    {
        $this->requirePostgres();

        $countingId = (string) Str::uuid();
        $this->countingMovement($countingId, null);

        $this->expectException(QueryException::class);
        $this->countingMovement($countingId, null);
    }

    public function test_duplicate_counting_apply_movement_is_rejected_for_variant_rows(): void
    {
        $this->requirePostgres();

        $countingId = (string) Str::uuid();
        $variantId = $this->variant('RED')->id;
        $this->countingMovement($countingId, $variantId);

        $this->expectException(QueryException::class);
        $this->countingMovement($countingId, $variantId);
    }

    /**
     * Negative control: the index must not collide across countings, across
     * variants of the same product, or with non-counting movements.
     */
    public function test_distinct_grains_and_non_counting_movements_are_unaffected(): void
    {
        $this->requirePostgres();

        $countingA = (string) Str::uuid();
        $countingB = (string) Str::uuid();

        $this->countingMovement($countingA, null);
        $this->countingMovement($countingB, null);
        $this->countingMovement($countingA, $this->variant('RED')->id);
        $this->countingMovement($countingA, $this->variant('BLUE')->id);

        // Two movements with NO counting linkage on the same grain stay legal.
        $this->countingMovement(null, null);
        $this->countingMovement(null, null);

        $this->assertSame(6, StockMovement::query()->count());
    }

    // --- Helpers ---

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes are PostgreSQL-only.');
        }
    }

    private function freshStatus(InventoryCounting $counting): CountingStatus
    {
        return ($counting->fresh() ?? $counting)->status;
    }

    private function pendingReviewCounting(): InventoryCounting
    {
        return InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'counting_number' => 'CNT-'.substr(uniqid(), -8),
            'status' => CountingStatus::PendingReview,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
            'count_1_user_id' => $this->user->id,
        ]);
    }

    private function resolvedItem(
        InventoryCounting $counting,
        string $theoretical,
        string $final,
    ): InventoryCountingItem {
        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'variant_id' => null,
            'theoretical_qty' => $theoretical,
            'count_1_qty' => $final,
            'final_qty' => $final,
            // final_qty_as_of stays null: the legacy `final − theoretical`
            // delta path, which posts one movement per line.
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);
    }

    private function variant(string $suffix): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => $suffix,
            'sku' => $this->product->sku.'-'.$suffix,
            'name_suffix' => $suffix,
            'is_active' => true,
        ]);
    }

    private function countingMovement(?string $countingId, ?string $variantId): StockMovement
    {
        return StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'variant_id' => $variantId,
            'movement_type' => MovementType::Adjustment,
            'quantity' => '1.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '1.0000',
            'reference' => 'COUNT_REPLAY',
            'reference_type' => $countingId === null
                ? null
                : StockMovementReferenceType::InventoryCounting->value,
            'reference_id' => $countingId,
            'occurred_at' => now(),
        ]);
    }
}
