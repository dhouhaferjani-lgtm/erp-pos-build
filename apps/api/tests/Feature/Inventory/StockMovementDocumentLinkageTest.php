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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'tax_id' => 'LNK-TAX-'.uniqid(),
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
            'code' => 'WH-LNK-'.uniqid(),
            'name' => 'Linkage Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);

        $this->secondLocation = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-LNK2-'.uniqid(),
            'name' => 'Linkage Warehouse 2',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
            'onboarding_mode' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LNK-'.uniqid(),
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
            'counting_number' => 'CNT-LNK-'.uniqid(),
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
            referenceType: 'Document',
            referenceId: $documentId,
        );

        $movement->refresh();
        $this->assertSame('Document', $movement->reference_type);
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
            referenceType: 'Document',
            referenceId: $documentId,
        );

        $movement->refresh();
        $this->assertSame('Document', $movement->reference_type);
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
            referenceType: 'StockTransfer',
            referenceId: $documentId,
        );

        foreach ([MovementType::TransferOut, MovementType::TransferIn] as $type) {
            $movement = StockMovement::query()
                ->where('product_id', $this->product->id)
                ->where('movement_type', $type->value)
                ->firstOrFail();

            $this->assertSame('StockTransfer', $movement->reference_type, $type->value.' leg lost the linkage');
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
            referenceType: InventoryCounting::class,
            referenceId: $documentId,
        );

        $movement->refresh();
        $this->assertSame(InventoryCounting::class, $movement->reference_type);
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
            referenceType: 'Document',
            referenceId: null,
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

        $this->assertSame(InventoryCounting::class, $movement->reference_type);
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

        $this->assertSame(InventoryCounting::class, $movement->reference_type);
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

        $this->assertSame(InventoryCounting::class, $movement->reference_type);
        $this->assertSame($counting->id, $movement->reference_id);
    }
}
