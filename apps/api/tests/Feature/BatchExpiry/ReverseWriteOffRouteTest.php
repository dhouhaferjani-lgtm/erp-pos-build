<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task C2 — HTTP wiring for the write-off reversal endpoint.
 *
 * POST /api/v1/stock-movements/{movementId}/reverse-write-off
 */
final class ReverseWriteOffRouteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Reverse Route Tenant',
            'slug' => 'rev-route-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reverse Route Company',
            'legal_name' => 'Reverse Route Company LLC',
            'tax_id' => 'RR-TAX-'.uniqid(),
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
            'name' => 'Reverse Route Admin',
            'email' => 'rev-route-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-RR-01',
            'name' => 'Reverse Route Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RR-PROD-001',
            'name' => 'Reverse Route Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.234',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '50.0000',
            'reserved' => '0.0000',
        ]);

        $this->batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-RR-001',
            'expiry_date' => now()->addYear(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $this->batch->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '50.0000',
            'reserved_quantity' => '0.0000',
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '601',
            'name' => 'Cost of Goods Sold',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::InventoryShrinkageExpense,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '311',
            'name' => 'Inventory Asset',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
        ]);
    }

    private function writeOff(): StockMovement
    {
        return app(BatchWriteOffService::class)->writeOff(
            batch: $this->batch,
            locationId: $this->warehouse->id,
            quantity: '10.0000',
            reason: 'expiry',
            userId: $this->user->id,
        );
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $movement = $this->writeOff();

        $this->postJson('/api/v1/stock-movements/'.$movement->id.'/reverse-write-off')
            ->assertUnauthorized();
    }

    public function test_admin_can_reverse_write_off_and_stock_is_restored(): void
    {
        $movement = $this->writeOff();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/stock-movements/'.$movement->id.'/reverse-write-off');

        $response->assertOk()
            ->assertJsonPath('data.original_movement_id', $movement->id);

        $this->assertSame('50.0000', (string) StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));
        $this->assertSame('50.0000', (string) BatchStock::query()
            ->where('batch_id', $this->batch->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));
    }

    public function test_double_reverse_returns_409(): void
    {
        $movement = $this->writeOff();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/stock-movements/'.$movement->id.'/reverse-write-off')
            ->assertOk();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/stock-movements/'.$movement->id.'/reverse-write-off')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'WRITE_OFF_ALREADY_REVERSED');
    }

    public function test_unknown_movement_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/stock-movements/'.Str::uuid()->toString().'/reverse-write-off')
            ->assertStatus(404);
    }

    public function test_user_without_write_off_permission_is_forbidden(): void
    {
        $movement = $this->writeOff();

        $viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $viewer->givePermissionTo('inventory.view');

        UserCompanyMembership::create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Viewer,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/stock-movements/'.$movement->id.'/reverse-write-off')
            ->assertStatus(403);
    }
}
