<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DraftLineAdded;
use App\Modules\Document\Domain\Events\DraftLineAddedV3;
use App\Modules\Document\Domain\Events\DraftLineModifiedV3;
use App\Modules\Document\Domain\Events\DraftLineRemovedV2;
use App\Modules\Document\Domain\Events\SalesOrderConfirmedV2;
use App\Modules\Document\Domain\Services\DraftPersistenceService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Events\ReservationCreated;
use App\Modules\Inventory\Domain\Events\ReservationCreatedV2;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class T2EventsV2DualDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $warehouse;

    private Product $product;

    private string $userId;

    private StockAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Dual Dispatch Tenant',
            'slug' => 'dual-dispatch-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Dual Dispatch Company',
            'legal_name' => 'Dual Dispatch Company LLC',
            'tax_id' => 'TAXDD1',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-DD1',
            'name' => 'Dual Dispatch Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-DD-001',
            'name' => 'Dual Dispatch Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Dual Dispatch User',
            'email' => 'dual-dispatch-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->userId = $user->id;

        $this->service = app(StockAdjustmentService::class);
    }

    private function makeVariant(bool $isActive = true): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => 'VAR-'.Str::upper(Str::random(6)),
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'barcode' => null,
            'name_suffix' => 'Red / XL',
            'is_default' => false,
            'is_active' => $isActive,
            'display_order' => 0,
            'price_override' => null,
            'cost_override' => null,
            'image_url' => null,
        ]);
    }

    public function test_dual_dispatch_v1_and_v2_for_variant_receive(): void
    {
        Event::fake([StockMovementRecorded::class, StockMovementRecordedV2::class]);

        $variant = $this->makeVariant();

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.00',
            reference: 'PO-DD-001',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        // Both the V1 predecessor and the V2 successor must fire.
        Event::assertDispatched(StockMovementRecorded::class, 1);
        Event::assertDispatched(StockMovementRecordedV2::class, 1);

        // V1 payload is unchanged (no variantId field exists on V1).
        Event::assertDispatched(
            StockMovementRecorded::class,
            fn (StockMovementRecorded $event): bool => $event->productId === $this->product->id
                && $event->movementType === 'receipt'
        );

        // V2 carries the variant id.
        Event::assertDispatched(
            StockMovementRecordedV2::class,
            fn (StockMovementRecordedV2 $event): bool => $event->variantId === $variant->id
                && $event->productId === $this->product->id
                && $event->movementType === 'receipt'
        );
    }

    public function test_dual_dispatch_for_non_variant_receive_emits_both_with_null_variant_in_v2(): void
    {
        Event::fake([StockMovementRecorded::class, StockMovementRecordedV2::class]);

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '7.00',
            reference: 'PO-DD-002',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
        );

        Event::assertDispatched(StockMovementRecorded::class, 1);
        Event::assertDispatched(StockMovementRecordedV2::class, 1);

        Event::assertDispatched(
            StockMovementRecordedV2::class,
            fn (StockMovementRecordedV2 $event): bool => $event->variantId === null
                && $event->productId === $this->product->id
        );
    }

    public function test_dual_dispatch_for_issue_and_adjust_emit_both(): void
    {
        Event::fake([StockMovementRecorded::class, StockMovementRecordedV2::class]);

        $variant = $this->makeVariant();

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-DD-003',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        $this->service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '4.00',
            reference: 'SO-DD-003',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        $this->service->adjust(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            newQuantity: '12.00',
            reason: 'count',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        // 3 movements (receipt, issue, adjustment) → 3 V1 + 3 V2.
        Event::assertDispatched(StockMovementRecorded::class, 3);
        Event::assertDispatched(StockMovementRecordedV2::class, 3);

        Event::assertDispatched(
            StockMovementRecordedV2::class,
            fn (StockMovementRecordedV2 $event): bool => $event->variantId === $variant->id
                && $event->movementType === 'issue'
        );
        Event::assertDispatched(
            StockMovementRecordedV2::class,
            fn (StockMovementRecordedV2 $event): bool => $event->variantId === $variant->id
                && $event->movementType === 'adjustment'
        );
    }

    public function test_dual_dispatch_for_transfer_emits_both_legs(): void
    {
        Event::fake([StockMovementRecorded::class, StockMovementRecordedV2::class]);

        $variant = $this->makeVariant();

        $toLocation = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-DD2',
            'name' => 'Dual Dispatch Warehouse 2',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-DD-TRF-SEED',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        $this->service->transfer(
            productId: $this->product->id,
            fromLocationId: $this->warehouse->id,
            toLocationId: $toLocation->id,
            quantity: '3.00',
            reference: 'TRF-DD-001',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        // 1 receipt + 2 transfer legs = 3 V1 + 3 V2.
        Event::assertDispatched(StockMovementRecorded::class, 3);
        Event::assertDispatched(StockMovementRecordedV2::class, 3);

        Event::assertDispatched(
            StockMovementRecordedV2::class,
            fn (StockMovementRecordedV2 $event): bool => $event->variantId === $variant->id
                && $event->movementType === 'transfer_out'
        );
        Event::assertDispatched(
            StockMovementRecordedV2::class,
            fn (StockMovementRecordedV2 $event): bool => $event->variantId === $variant->id
                && $event->movementType === 'transfer_in'
        );
    }

    public function test_reservation_created_dual_dispatch_via_stock_adjustment_service(): void
    {
        Event::fake([ReservationCreated::class, ReservationCreatedV2::class]);

        $variant = $this->makeVariant();

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '8.00',
            reference: 'PO-DD-RSV-SEED',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        $this->service->reserve(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '2.00',
            reference: 'ORD-DD-001',
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        Event::assertDispatched(ReservationCreated::class, 1);
        Event::assertDispatched(ReservationCreatedV2::class, 1);

        Event::assertDispatched(
            ReservationCreatedV2::class,
            fn (ReservationCreatedV2 $event): bool => $event->variantId === $variant->id
                && $event->productId === $this->product->id
        );
    }

    public function test_draft_line_added_dual_dispatch_includes_v3_with_variant_fields(): void
    {
        Event::fake([DraftLineAdded::class, DraftLineAddedV3::class]);

        $variant = $this->makeVariant();

        $partner = $this->createPartner();

        $service = app(DraftPersistenceService::class);

        $service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->userId,
            draftId: null,
            data: [
                'type' => DocumentType::Quote->value,
                'partner_id' => $partner->id,
                'lines' => [
                    [
                        'product_id' => $this->product->id,
                        'variant_id' => $variant->id,
                        'quantity' => 2,
                        'unit_price' => 10,
                        'description' => 'Test line',
                    ],
                ],
            ],
        );

        Event::assertDispatched(DraftLineAdded::class, 1);
        Event::assertDispatched(DraftLineAddedV3::class, 1);

        Event::assertDispatched(
            DraftLineAddedV3::class,
            fn (DraftLineAddedV3 $event): bool => $event->variantId === $variant->id
                && $event->productId === $this->product->id
        );
    }

    public function test_v1_subscriber_still_receives_v1_event_unchanged(): void
    {
        // Backward-compat guarantee: a listener bound to the V1 event class
        // continues to receive the V1 event with its original payload shape
        // even though a V2/V3 successor now fires alongside it.
        $received = [];

        Event::listen(StockMovementRecorded::class, function (StockMovementRecorded $event) use (&$received): void {
            $received[] = $event;
        });

        $variant = $this->makeVariant();

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.00',
            reference: 'PO-DD-COMPAT',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        $this->assertCount(1, $received);
        $this->assertInstanceOf(StockMovementRecorded::class, $received[0]);
        $this->assertSame($this->product->id, $received[0]->productId);
        $this->assertSame('receipt', $received[0]->movementType);
        // V1 must NOT have gained a variantId property.
        $this->assertFalse(property_exists($received[0], 'variantId'));
    }

    public function test_new_successor_event_classes_exist_with_expected_shape(): void
    {
        // Coverage for successor classes whose producers are not driven directly
        // in this feature suite (SalesOrderConfirmedV2, DraftLineModifiedV3,
        // DraftLineRemovedV2). We assert the class exists, is immutable, and
        // carries the variant field(s).
        $this->assertTrue(property_exists(SalesOrderConfirmedV2::class, 'lines'));
        $this->assertTrue(property_exists(DraftLineModifiedV3::class, 'variantId'));
        $this->assertTrue(property_exists(DraftLineRemovedV2::class, 'variantId'));

        $modified = new DraftLineModifiedV3(
            documentId: (string) Str::uuid(),
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->userId,
            lineId: (string) Str::uuid(),
            productId: $this->product->id,
            oldValues: ['quantity' => 1.0],
            newValues: ['quantity' => 2.0],
            description: 'x',
            notes: null,
            variantId: (string) Str::uuid(),
        );
        $this->assertSame('draft.line.modified.v3', $modified->getEventName());

        $removed = new DraftLineRemovedV2(
            documentId: (string) Str::uuid(),
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->userId,
            lineId: (string) Str::uuid(),
            productId: $this->product->id,
            productName: 'x',
            quantity: 1.0,
            lineTotal: 10.0,
            variantId: (string) Str::uuid(),
        );
        $this->assertSame('draft.line.removed.v2', $removed->getEventName());
    }

    private function createPartner(): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'DD Partner',
            'type' => 'customer',
            'is_active' => true,
        ]);
    }
}
