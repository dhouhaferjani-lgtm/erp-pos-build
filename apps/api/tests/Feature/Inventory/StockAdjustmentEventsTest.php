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
use App\Modules\Inventory\Domain\Events\ReservationCreated;
use App\Modules\Inventory\Domain\Events\ReservationReleased;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockAdjustmentEventsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Location $secondWarehouse;

    private Product $product;

    private StockAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-events',
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

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'events-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->secondWarehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-02',
            'name' => 'Secondary Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'EVT-PROD-001',
            'name' => 'Event Test Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        $this->service = app(StockAdjustmentService::class);
    }

    public function test_receive_dispatches_stock_movement_recorded_event(): void
    {
        Event::fake([StockMovementRecorded::class]);

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return $event->productId === $this->product->id
                && $event->locationId === $this->warehouse->id
                && $event->movementType === 'receipt'
                && $event->quantity === '10.00'
                && $event->newStockLevel === '10.00';
        });
    }

    public function test_issue_dispatches_stock_movement_recorded_event(): void
    {
        // Receive stock first (without faking events)
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        Event::fake([StockMovementRecorded::class]);

        $this->service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );

        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return $event->productId === $this->product->id
                && $event->locationId === $this->warehouse->id
                && $event->movementType === 'issue'
                && $event->quantity === '5.00'
                && $event->newStockLevel === '15.00';
        });
    }

    public function test_adjust_dispatches_stock_movement_recorded_event(): void
    {
        // Receive stock first
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        Event::fake([StockMovementRecorded::class]);

        $this->service->adjust(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            newQuantity: '12.00',
            reason: 'Physical inventory count',
            userId: $this->user->id,
        );

        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return $event->productId === $this->product->id
                && $event->locationId === $this->warehouse->id
                && $event->movementType === 'adjustment'
                && $event->quantity === '2.00'
                && $event->newStockLevel === '12.00';
        });
    }

    public function test_transfer_dispatches_two_stock_movement_recorded_events(): void
    {
        // Receive stock first
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        Event::fake([StockMovementRecorded::class]);

        $this->service->transfer(
            productId: $this->product->id,
            fromLocationId: $this->warehouse->id,
            toLocationId: $this->secondWarehouse->id,
            quantity: '8.00',
            reference: 'TR-001',
            userId: $this->user->id,
        );

        Event::assertDispatched(StockMovementRecorded::class, 2);

        // Source movement (transfer out)
        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return $event->locationId === $this->warehouse->id
                && $event->movementType === 'transfer_out'
                && $event->newStockLevel === '12.00';
        });

        // Destination movement (transfer in)
        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return $event->locationId === $this->secondWarehouse->id
                && $event->movementType === 'transfer_in'
                && $event->newStockLevel === '8.00';
        });
    }

    public function test_reserve_dispatches_reservation_created_event(): void
    {
        // Receive stock first
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        Event::fake([ReservationCreated::class]);

        $this->service->reserve(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.00',
            reference: 'ORDER-001',
        );

        Event::assertDispatched(ReservationCreated::class, function (ReservationCreated $event): bool {
            return $event->productId === $this->product->id
                && $event->locationId === $this->warehouse->id
                && $event->quantity === '5.00'
                && $event->sourceType === 'reservation'
                && $event->sourceId === 'ORDER-001';
        });
    }

    public function test_release_reservation_dispatches_reservation_released_event(): void
    {
        // Receive and reserve stock first
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $this->service->reserve(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.00',
            reference: 'ORDER-001',
        );

        Event::fake([ReservationReleased::class]);

        $this->service->releaseReservation(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '3.00',
            reference: 'ORDER-001-CANCEL',
        );

        Event::assertDispatched(ReservationReleased::class, function (ReservationReleased $event): bool {
            return $event->productId === $this->product->id
                && $event->locationId === $this->warehouse->id
                && $event->quantity === '3.00'
                && $event->releaseReason === 'manual_release';
        });
    }
}
