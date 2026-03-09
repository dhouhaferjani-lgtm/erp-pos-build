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
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ActivateDraftCountingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $counterUser;

    private User $otherUser;

    private Location $warehouse;

    /** @var array<int, Product> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant AD',
            'slug' => 'test-tenant-ad',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company AD',
            'legal_name' => 'Test Company AD LLC',
            'tax_id' => 'TAXAD',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin AD',
            'email' => 'admin-ad@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');
        $this->adminUser->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->counterUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counter AD',
            'email' => 'counter-ad@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->counterUser->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->counterUser->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        $this->otherUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other AD',
            'email' => 'other-ad@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->otherUser->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->otherUser->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-AD',
            'name' => 'AD Test Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $stockService = app(StockAdjustmentService::class);
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'sku' => "PROD-AD-{$i}",
                'name' => "AD Product {$i}",
                'type' => ProductType::Part,
                'is_active' => true,
            ]);
            $this->products[] = $product;

            $stockService->receive(
                productId: $product->id,
                locationId: $this->warehouse->id,
                quantity: (string) ($i * 10),
                reference: "PO-AD-{$i}",
                userId: $this->adminUser->id,
            );
        }
    }

    public function test_activate_draft_returns_200_with_counting_number(): void
    {
        $draft = $this->createDraft($this->counterUser);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft", [
                'activate_immediately' => true,
            ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.counting_number'));
        $this->assertMatchesRegularExpression(
            '/^CNT-\d{4}-\d{4}$/',
            $response->json('data.counting_number'),
        );
    }

    public function test_activate_draft_rejects_non_draft_status(): void
    {
        $counting = $this->createDraft($this->counterUser);
        $counting->status = CountingStatus::Count1InProgress;
        $counting->activated_at = now();
        $counting->save();

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/activate-draft");

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'Can only activate draft counts']);
    }

    public function test_activate_draft_rejects_empty_product_list(): void
    {
        $draft = $this->createDraft($this->counterUser, [
            'scope_filters' => ['product_ids' => []],
        ]);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'At least one product',
            $response->json('error'),
        );
    }

    public function test_activate_draft_rejects_without_primary_counter(): void
    {
        $draft = $this->createDraft($this->counterUser, [
            'count_1_user_id' => null,
        ]);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Primary counter must be assigned',
            $response->json('error'),
        );
    }

    public function test_activate_draft_rejects_unauthorized_user(): void
    {
        // Draft created by counterUser
        $draft = $this->createDraft($this->counterUser);

        // otherUser (not creator, not admin) tries to activate
        $response = $this->actingAs($this->otherUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(403);
    }

    public function test_activate_draft_allows_admin_to_activate_others_draft(): void
    {
        // Draft created by counterUser
        $draft = $this->createDraft($this->counterUser);

        // Admin activates it
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(200);
    }

    public function test_show_endpoint_includes_counting_number(): void
    {
        $draft = $this->createDraft($this->counterUser);

        // Activate to generate counting number
        $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response = $this->actingAs($this->counterUser)
            ->getJson("/api/v1/inventory/countings/{$draft->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['counting_number'],
        ]);
        $this->assertNotNull($response->json('data.counting_number'));
    }

    // --- Helpers ---

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDraft(User $createdBy, array $overrides = []): InventoryCounting
    {
        $productIds = array_map(fn (Product $p) => $p->id, $this->products);

        $defaults = [
            'company_id' => $this->company->id,
            'created_by_user_id' => $createdBy->id,
            'status' => CountingStatus::Draft,
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => $productIds],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'count_1_user_id' => $this->counterUser->id,
        ];

        return InventoryCounting::create(array_merge($defaults, $overrides));
    }
}
