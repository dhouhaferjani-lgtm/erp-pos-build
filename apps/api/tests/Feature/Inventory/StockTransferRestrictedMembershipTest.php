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
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StockTransferRestrictedMembershipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $locationA;

    private Location $locationB;

    private Location $locationC;

    private Product $product;

    private User $restricted;

    private User $unrestricted;

    private StockTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Restricted Transfer Tenant',
            'slug' => 'restricted-transfer-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Restricted Transfer Company',
            'legal_name' => 'Restricted Transfer Company LLC',
            'tax_id' => 'TAX-TRANSFER',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->locationA = $this->makeLocation('A');
        $this->locationB = $this->makeLocation('B');
        $this->locationC = $this->makeLocation('C');
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RESTRICTED-TRANSFER',
            'name' => 'Restricted Transfer Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);
        $this->restricted = $this->makeUser('restricted-transfer@example.test', [$this->locationA->id]);
        $this->unrestricted = $this->makeUser('unrestricted-transfer@example.test', null);
        $this->service = app(StockTransferService::class);
    }

    public function test_restricted_user_only_sees_transfers_touching_allowed_location(): void
    {
        $visible = $this->makeTransfer($this->locationA, $this->locationB);
        $hidden = $this->makeTransfer($this->locationB, $this->locationC);

        $response = $this->actingAs($this->restricted)
            ->getJson('/api/v1/stock-transfers')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_restricted_user_can_create_transfer_from_allowed_location(): void
    {
        $this->seedStock($this->locationA, '5');

        $this->actingAs($this->restricted)
            ->postJson('/api/v1/stock-transfers', [
                'source_location_id' => $this->locationA->id,
                'destination_location_id' => $this->locationB->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '2'],
                ],
            ])
            ->assertCreated();
    }

    public function test_restricted_user_cannot_create_transfer_from_disallowed_location(): void
    {
        $this->seedStock($this->locationB, '5');

        $this->actingAs($this->restricted)
            ->postJson('/api/v1/stock-transfers', [
                'source_location_id' => $this->locationB->id,
                'destination_location_id' => $this->locationA->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '2'],
                ],
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'LOCATION_ACCESS_DENIED');
    }

    private function makeLocation(string $suffix): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'code' => 'LOC-'.$suffix,
            'name' => 'Location '.$suffix,
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => $suffix === 'A',
        ]);
    }

    /** @param array<int, string>|null $allowed */
    private function makeUser(string $email, ?array $allowed): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $user->givePermissionTo([
            'inventory.transfers.view',
            'inventory.transfers.create',
            'products.view',
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'allowed_location_ids' => $allowed,
            'status' => 'active',
        ]);

        return $user;
    }

    private function seedStock(Location $location, string $quantity): void
    {
        app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $location->id,
            quantity: $quantity,
            reference: 'RESTRICTED-TEST',
            userId: $this->unrestricted->id,
            expectedCompanyId: $this->company->id,
        );
    }

    private function makeTransfer(Location $source, Location $destination): StockTransfer
    {
        $this->seedStock($source, '5');

        return $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $source->id,
            destinationLocationId: $destination->id,
            initiatedByUserId: $this->unrestricted->id,
            lines: [
                new InitiateTransferLineData(
                    productId: $this->product->id,
                    quantity: '2',
                ),
            ],
        ));
    }
}
