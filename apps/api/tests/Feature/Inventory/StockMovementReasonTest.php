<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A1: the typed MovementReason must be persisted on the stock_movements row by
 * the StockAdjustmentService posting path (it was previously left NULL — only POS
 * projections set it). The reason column already exists and is cast; the gap was
 * that the service never wrote it.
 */
final class StockMovementReasonTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Reason Tenant',
            'slug' => 'reason-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reason Company',
            'legal_name' => 'Reason Company LLC',
            'tax_id' => 'RSN-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reason User',
            'email' => 'reason-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-RSN-01',
            'name' => 'Reason Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RSN-001',
            'name' => 'Reason Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '100.0000',
            'reserved' => '0.0000',
        ]);
    }

    public function test_issue_persists_the_movement_reason(): void
    {
        $movement = app(StockAdjustmentService::class)->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.0000',
            reference: 'manual issue',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            reason: MovementReason::WriteOff,
        );

        $this->assertSame(MovementReason::WriteOff, $movement->reason);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'reason' => MovementReason::WriteOff->value,
        ]);
    }

    public function test_receive_persists_the_movement_reason(): void
    {
        $movement = app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.0000',
            reference: 'goods in',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            reason: MovementReason::GoodsReceipt,
        );

        $this->assertSame(MovementReason::GoodsReceipt, $movement->reason);
    }

    public function test_adjust_persists_the_movement_reason_code(): void
    {
        $movement = app(StockAdjustmentService::class)->adjust(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            newQuantity: '90.0000',
            reason: 'cycle count correction',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            reasonCode: MovementReason::AdjustmentNegative,
        );

        $this->assertSame(MovementReason::AdjustmentNegative, $movement->reason);
    }
}
