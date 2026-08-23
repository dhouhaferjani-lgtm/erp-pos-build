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
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Domain\Enums\AssignmentStatus;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingAssignment;
use App\Modules\Inventory\Domain\InventoryCountingItem;
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
 * Session B lane Q-2 / Inventory H-2 — the last-item submission race.
 *
 * `checkPhaseCompletion()` did an unlocked count-then-act against a STALE
 * in-memory counting handed down from `submitCount()`. Two counters finishing
 * the last items of a phase concurrently both observed "all counted", so the
 * loser re-drove the phase transition on a stale status (silently regressing
 * the counting and re-running the phase's side effects), and the "phase already
 * advanced" refusal surfaced as a bare 500 rather than the module's 422.
 */
final class CountingSubmitCountRaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $productA;

    private Product $productB;

    private InventoryCountingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Submit Race Tenant',
            'slug' => 'submit-race-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Submit Race Company',
            'legal_name' => 'Submit Race Company LLC',
            'tax_id' => 'SRC-TAX-'.uniqid(),
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
            'name' => 'Submit Race Counter',
            'email' => 'submit-race-'.uniqid().'@example.com',
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
            'code' => 'WH-SRC-'.uniqid(),
            'name' => 'Submit Race Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);

        $this->productA = $this->product('SRC-A-');
        $this->productB = $this->product('SRC-B-');

        $this->service = app(InventoryCountingService::class);
    }

    /**
     * The interleave: counter A committed the last item and advanced the phase
     * while counter B's request was already in flight holding a stale counting
     * snapshot. B's submit must persist its quantity and must NOT re-drive the
     * phase transition on the stale status.
     */
    public function test_a_stale_last_item_submission_does_not_re_drive_the_phase_transition(): void
    {
        $counting = $this->activeCounting(requiresCount2: true);
        $itemA = $this->item($counting, $this->productA);
        $itemB = $this->item($counting, $this->productB);

        $assignment1 = InventoryCountingAssignment::create([
            'counting_id' => $counting->id,
            'user_id' => $this->user->id,
            'count_number' => 1,
            'status' => AssignmentStatus::InProgress,
            'assigned_at' => now(),
            'started_at' => now(),
            'total_items' => 2,
            'counted_items' => 0,
        ]);
        $assignment2 = InventoryCountingAssignment::create([
            'counting_id' => $counting->id,
            'user_id' => $this->user->id,
            'count_number' => 2,
            'status' => AssignmentStatus::Pending,
            'assigned_at' => now(),
            'total_items' => 2,
            'counted_items' => 0,
        ]);

        // Counter B's in-flight handle: loaded while the phase was still
        // count_1_in_progress.
        $staleItemB = InventoryCountingItem::findOrFail($itemB->id);
        $staleItemB->setRelation('counting', InventoryCounting::findOrFail($counting->id));

        // Counter A wins the race and closes phase 1.
        $this->service->submitCount(
            InventoryCountingItem::findOrFail($itemA->id),
            1,
            '5.0000',
            null,
            $this->user,
        );
        $this->service->submitCount(
            InventoryCountingItem::findOrFail($itemB->id),
            1,
            '7.0000',
            null,
            $this->user,
        );
        $this->assertSame(CountingStatus::Count2InProgress, $this->freshStatus($counting));

        $assignment2->refresh();
        $startedAtAfterWinner = $assignment2->started_at?->toIso8601String();

        // Move the clock so a re-`start()` of count 2's assignment would stamp a
        // visibly different `started_at`.
        $this->travel(5)->minutes();

        // Every status write performed from here on is a defect: the phase was
        // already advanced by counter A.
        /** @var list<string> $statusWrites */
        $statusWrites = [];
        InventoryCounting::updated(function (InventoryCounting $written) use (&$statusWrites): void {
            if ($written->wasChanged('status')) {
                $statusWrites[] = $written->status->value;
            }
        });

        // Counter B's late submission lands on the stale snapshot.
        $this->service->submitCount($staleItemB, 1, '9.0000', null, $this->user);

        $this->assertSame(
            [],
            $statusWrites,
            'A late submission must not re-drive the phase transition on a stale counting.'
        );

        $this->assertSame(
            CountingStatus::Count2InProgress,
            $this->freshStatus($counting),
            'The counting status must stay where the winning submitter left it.'
        );

        $this->assertSame(
            '9.0000',
            InventoryCountingItem::findOrFail($itemB->id)->count_1_qty,
            'The late counter\'s submitted quantity must be persisted, not rolled back.'
        );

        $assignment2->refresh();
        $this->assertSame(
            $startedAtAfterWinner,
            $assignment2->started_at?->toIso8601String(),
            'Count 2\'s assignment must not be re-started by the late submission.'
        );

        $assignment1->refresh();
        $this->assertSame(AssignmentStatus::Completed, $assignment1->status);
    }

    /**
     * A submission arriving after the phase has advanced is a business-rule
     * refusal (the count session moved on), not a server fault: it must render
     * as the module's 422 envelope rather than a bare 500.
     */
    public function test_submitting_into_an_already_advanced_phase_renders_422_not_500(): void
    {
        $counting = $this->activeCounting(requiresCount2: false);
        $item = $this->item($counting, $this->productA);

        // The phase advanced (another counter finished it) before this request
        // reached the server.
        $counting->status = CountingStatus::PendingReview;
        $counting->save();

        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/count",
                ['quantity' => '4.0000']
            );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BUSINESS_ERROR');

        $this->assertNull(
            InventoryCountingItem::findOrFail($item->id)->count_1_qty,
            'The refused submission must not have written a quantity.'
        );
    }

    // --- Helpers ---

    private function freshStatus(InventoryCounting $counting): CountingStatus
    {
        return ($counting->fresh() ?? $counting)->status;
    }

    private function product(string $prefix): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $prefix.uniqid(),
            'name' => 'Submit Race Product '.$prefix,
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000000',
        ]);
    }

    private function activeCounting(bool $requiresCount2): InventoryCounting
    {
        return InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'counting_number' => 'CNT-'.substr(uniqid(), -8),
            'status' => CountingStatus::Count1InProgress,
            'requires_count_2' => $requiresCount2,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
            'count_1_user_id' => $this->user->id,
            'count_2_user_id' => $requiresCount2 ? $this->user->id : null,
        ]);
    }

    private function item(InventoryCounting $counting, Product $product): InventoryCountingItem
    {
        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'variant_id' => null,
            'theoretical_qty' => '5.0000',
            'resolution_method' => ItemResolutionMethod::Pending,
        ]);
    }
}
