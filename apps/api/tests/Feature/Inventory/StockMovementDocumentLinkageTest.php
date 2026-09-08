<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DPA S0 — document-linkage seam in StockAdjustmentService::recordMovement().
 *
 * `stock_movements` has carried `reference_type`/`reference_id` since migration
 * 2025_12_24_133827, but StockAdjustmentService could only stamp the free-text
 * `reference` label — so every movement it produced (including the
 * document-compliant counting finalize path) was document-UNlinked.
 *
 * These tests pin the seam: every public entry point can carry an optional
 * (referenceType, referenceId) pair that reaches the row, omitting it leaves
 * BOTH columns null (regression guard for the existing callers), and a
 * half-specified pair is rejected rather than persisted as an unusable morph.
 */
final class StockMovementDocumentLinkageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Location $secondLocation;

    private Product $product;

    private StockAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Linkage Tenant',
            'slug' => 'linkage-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Linkage Company',
            'legal_name' => 'Linkage Company LLC',
            'tax_id' => 'TX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Linkage User',
            'email' => 'linkage-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'L1-'.uniqid(),
            'name' => 'Linkage Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);

        $this->secondLocation = Location::create([
            'company_id' => $this->company->id,
            'code' => 'L2-'.uniqid(),
            'name' => 'Linkage Warehouse 2',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
            'onboarding_mode' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'S-'.uniqid(),
            'name' => 'Linkage Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000000',
        ]);

        $this->service = app(StockAdjustmentService::class);
    }

    // ---------------------------------------------------------------- helpers

    private function setOnHand(string $quantity, ?Location $location = null): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => ($location ?? $this->location)->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function counting(bool $blockSales = false): InventoryCounting
    {
        return InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'counting_number' => 'C-'.uniqid(),
            'status' => CountingStatus::Finalized,
            'ambiguity_window_minutes' => 15,
            'block_sales' => $blockSales,
            'created_by_user_id' => $this->user->id,
        ]);
    }

    private function item(
        InventoryCounting $counting,
        string $finalQty,
        ?CarbonImmutable $finalQtyAsOf,
        string $theoretical = '0.0000',
    ): InventoryCountingItem {
        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => $theoretical,
            'final_qty' => $finalQty,
            'final_qty_as_of' => $finalQtyAsOf,
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);
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
            completedBy: $this->user->id,
            completedAt: now()->toIso8601String(),
        ));
    }

    // ------------------------------------------------- direct entry points

    public function test_receive_persists_document_linkage(): void
    {
        $documentId = (string) Str::uuid();

        $movement = $this->service->receive(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5.0000',
            reference: 'GR-0001',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            referenceType: StockMovementReferenceType::Document,
            referenceId: $documentId,
        );

        $movement->refresh();
        $this->assertSame(StockMovementReferenceType::Document->value, $movement->reference_type);
        $this->assertSame($documentId, $movement->reference_id);
        // The free-text label is preserved alongside the FK, not replaced.
        $this->assertSame('GR-0001', $movement->reference);
    }

    public function test_issue_persists_document_linkage(): void
    {
        $this->setOnHand('10.0000');
        $documentId = (string) Str::uuid();

        $movement = $this->service->issue(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '4.0000',
            reference: 'DN-0001',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            referenceType: StockMovementReferenceType::Document,
            referenceId: $documentId,
        );

        $movement->refresh();
        $this->assertSame(StockMovementReferenceType::Document->value, $movement->reference_type);
        $this->assertSame($documentId, $movement->reference_id);
    }

    public function test_transfer_persists_document_linkage_on_both_legs(): void
    {
        $this->setOnHand('10.0000');
        $this->setOnHand('0.0000', $this->secondLocation);
        $documentId = (string) Str::uuid();

        $this->service->transfer(
            productId: $this->product->id,
            fromLocationId: $this->location->id,
            toLocationId: $this->secondLocation->id,
            quantity: '3.0000',
            reference: 'TRF-0001',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            referenceType: StockMovementReferenceType::Document,
            referenceId: $documentId,
        );

        foreach ([MovementType::TransferOut, MovementType::TransferIn] as $type) {
            $movement = StockMovement::query()
                ->where('product_id', $this->product->id)
                ->where('movement_type', $type->value)
                ->firstOrFail();

            $this->assertSame(StockMovementReferenceType::Document->value, $movement->reference_type, $type->value.' leg lost the linkage');
            $this->assertSame($documentId, $movement->reference_id, $type->value.' leg lost the linkage');
        }
    }

    public function test_adjust_persists_document_linkage(): void
    {
        $this->setOnHand('10.0000');
        $documentId = (string) Str::uuid();

        $movement = $this->service->adjust(
            productId: $this->product->id,
            locationId: $this->location->id,
            newQuantity: '12.0000',
            reason: 'ADJ-0001',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            referenceType: StockMovementReferenceType::InventoryCounting,
            referenceId: $documentId,
        );

        $movement->refresh();
        $this->assertSame(StockMovementReferenceType::InventoryCounting->value, $movement->reference_type);
        $this->assertSame($documentId, $movement->reference_id);
    }

    // --------------------------------------------------- regression guards

    public function test_movements_without_linkage_leave_both_reference_columns_null(): void
    {
        $received = $this->service->receive(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '6.0000',
            reference: 'NO-LINK-IN',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        $issued = $this->service->issue(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '2.0000',
            reference: 'NO-LINK-OUT',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        foreach ([$received, $issued] as $movement) {
            $movement->refresh();
            $this->assertNull($movement->reference_type);
            $this->assertNull($movement->reference_id);
        }
    }

    public function test_transfer_and_adjust_without_linkage_leave_both_reference_columns_null(): void
    {
        $this->setOnHand('10.0000');
        $this->setOnHand('0.0000', $this->secondLocation);

        $this->service->transfer(
            productId: $this->product->id,
            fromLocationId: $this->location->id,
            toLocationId: $this->secondLocation->id,
            quantity: '3.0000',
            reference: 'NO-LINK-TRF',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        $adjusted = $this->service->adjust(
            productId: $this->product->id,
            locationId: $this->location->id,
            newQuantity: '9.0000',
            reason: 'NO-LINK-ADJ',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        $movements = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->get();

        // Both transfer legs plus the adjustment.
        $this->assertCount(3, $movements);
        foreach ($movements as $movement) {
            $this->assertNull($movement->reference_type);
            $this->assertNull($movement->reference_id);
        }

        $this->assertNull($adjusted->refresh()->reference_type);
    }

    public function test_apply_count_result_without_linkage_leaves_both_reference_columns_null(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');

        $this->service->applyCountResult(
            productId: $this->product->id,
            locationId: $this->location->id,
            variantId: null,
            finalQty: '20.0000',
            finalQtyAsOf: $t,
            ambiguityWindowMinutes: 15,
            onboarding: false,
            openingUnitCost: null,
        );

        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('reason', MovementReason::CountCorrection->value)
            ->firstOrFail();

        $this->assertNull($movement->reference_type);
        $this->assertNull($movement->reference_id);
    }

    /**
     * QA-BUG-09 fallback pin. `applyCountResult()` gained an optional
     * `reference` label so the finalize listener can stamp the bare counting
     * number (`CNT-2026-0010`). A caller that supplies NO label — every caller
     * that is not the listener — must keep writing the documented
     * `COUNT_REPLAY` constant, unchanged. Green before AND after the fix.
     */
    public function test_apply_count_result_without_a_reference_falls_back_to_the_count_replay_constant(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');

        $this->service->applyCountResult(
            productId: $this->product->id,
            locationId: $this->location->id,
            variantId: null,
            finalQty: '20.0000',
            finalQtyAsOf: $t,
            ambiguityWindowMinutes: 15,
            onboarding: false,
            openingUnitCost: null,
        );

        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('reason', MovementReason::CountCorrection->value)
            ->firstOrFail();

        $this->assertSame('COUNT_REPLAY', $movement->reference);
    }

    public function test_half_specified_linkage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '1.0000',
            reference: 'HALF',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            referenceType: StockMovementReferenceType::Document,
            referenceId: null,
        );
    }

    /**
     * `stock_movements.reference_id` is a PostgreSQL `uuid` column, but the suite
     * runs on SQLite, which accepts any TEXT. Without this guard an adopting lane
     * that links to an int-keyed entity would be green in CI and 500 in prod.
     */
    public function test_non_uuid_reference_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '1.0000',
            reference: 'NOT-A-UUID',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            referenceType: StockMovementReferenceType::Document,
            referenceId: '42',
        );
    }

    // ------------------------------------------------- domain event stream

    /**
     * The audit trail (`getAuditData()`) and the channel integration read the
     * event, not the row — so the event must carry the same linkage the row does,
     * or a guard reading the event stream sees an unlinked movement.
     */
    public function test_dispatched_events_carry_the_same_linkage_as_the_row(): void
    {
        $documentId = (string) Str::uuid();

        Event::fake([StockMovementRecorded::class, StockMovementRecordedV2::class]);

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5.0000',
            reference: 'GR-EVT',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            referenceType: StockMovementReferenceType::Document,
            referenceId: $documentId,
        );

        Event::assertDispatched(
            StockMovementRecorded::class,
            fn (StockMovementRecorded $event): bool => $event->referenceType === StockMovementReferenceType::Document->value
                && $event->referenceId === $documentId
        );

        Event::assertDispatched(
            StockMovementRecordedV2::class,
            fn (StockMovementRecordedV2 $event): bool => $event->referenceType === StockMovementReferenceType::Document->value
                && $event->referenceId === $documentId
        );
    }

    public function test_count_events_carry_the_counting_linkage(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');

        $counting = $this->counting();
        $this->item($counting, '20.0000', $t);

        Event::fake([StockMovementRecorded::class, StockMovementRecordedV2::class]);

        $this->fire($counting);

        Event::assertDispatched(
            StockMovementRecorded::class,
            fn (StockMovementRecorded $event): bool => $event->referenceType === StockMovementReferenceType::InventoryCounting->value
                && $event->referenceId === $counting->id
        );
    }

    // ------------------------------------------------- counting listener

    public function test_replay_count_movement_links_to_the_counting_document(): void
    {
        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');

        $counting = $this->counting();
        $this->item($counting, '20.0000', $t);

        $this->fire($counting);

        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('reason', MovementReason::CountCorrection->value)
            ->firstOrFail();

        $this->assertSame(StockMovementReferenceType::InventoryCounting->value, $movement->reference_type);
        $this->assertSame($counting->id, $movement->reference_id);
    }

    public function test_legacy_count_movement_links_to_the_counting_document(): void
    {
        $this->setOnHand('10.0000');

        $counting = $this->counting();
        $this->item($counting, '12.0000', null, theoretical: '10.0000');

        $this->fire($counting);

        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('reason', MovementReason::CountCorrection->value)
            ->firstOrFail();

        $this->assertSame(StockMovementReferenceType::InventoryCounting->value, $movement->reference_type);
        $this->assertSame($counting->id, $movement->reference_id);
        // Free-text label unchanged — the FK is additive, not a replacement.
        $this->assertSame('COUNTING:'.$counting->counting_number, $movement->reference);
    }

    public function test_onboarding_opening_count_movement_links_to_the_counting_document(): void
    {
        $this->location->update(['onboarding_mode' => true]);

        $t = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('0.0000');

        $counting = $this->counting();
        $this->item($counting, '15.0000', $t);

        $this->fire($counting);

        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', MovementType::Opening->value)
            ->firstOrFail();

        $this->assertSame(StockMovementReferenceType::InventoryCounting->value, $movement->reference_type);
        $this->assertSame($counting->id, $movement->reference_id);
    }
}
