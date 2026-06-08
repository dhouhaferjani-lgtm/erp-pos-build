<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Enums\Vertical;
use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CatalogCartTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Mechanic',
            'slug' => 'test-mechanic-cart',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'CARTAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cart User',
            'email' => 'cart@test.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /** @test */
    public function it_creates_cart_for_user(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/catalog-carts', [
                'name' => 'My Brake Job Cart',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'My Brake Job Cart')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_shared', false);

        $this->assertDatabaseHas('catalog_carts', [
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'name' => 'My Brake Job Cart',
        ]);
    }

    /** @test */
    public function it_adds_catalog_item_to_cart(): void
    {
        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Brake Pads',
            'sku' => 'BP-001',
            'sale_price' => 45.000,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/items", [
                'source' => 'catalog',
                'article_name' => 'Brake Pads',
                'quantity' => '2',
                'unit_price' => '45.000',
                'currency' => 'TND',
                'product_id' => $product->id,
                'article_number' => 'BP-001',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.article_name', 'Brake Pads')
            ->assertJsonPath('data.source', 'catalog')
            ->assertJsonPath('data.quantity', '2.0000');
    }

    /** @test */
    public function it_adds_manual_item_to_cart(): void
    {
        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/items", [
                'source' => 'manual',
                'article_name' => 'Custom Gasket',
                'quantity' => '1',
                'unit_price' => '15.500',
                'currency' => 'TND',
                'notes' => 'Need exact dimensions',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.article_name', 'Custom Gasket')
            ->assertJsonPath('data.notes', 'Need exact dimensions');
    }

    /** @test */
    public function it_shares_cart_with_team_members(): void
    {
        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $teammate = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Teammate',
            'email' => 'teammate@test.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $teammate->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $teammate->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        // Share the cart
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/catalog-carts/{$cart->id}", [
                'is_shared' => true,
                'shared_with_user_ids' => [$teammate->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_shared', true);

        // Teammate should be able to see the cart
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $teammateResponse = $this->actingAs($teammate, 'sanctum')
            ->getJson("/api/v1/catalog-carts/{$cart->id}");

        $teammateResponse->assertStatus(200);
    }

    /** @test */
    public function it_removes_item_from_cart(): void
    {
        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $addResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/items", [
                'source' => 'catalog',
                'article_name' => 'Oil Filter',
                'quantity' => '1',
            ]);

        $itemId = $addResponse->json('data.id');

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/catalog-carts/{$cart->id}/items/{$itemId}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('catalog_cart_items', [
            'id' => $itemId,
        ]);
    }

    /** @test */
    public function it_requires_catalog_cart_create_permission(): void
    {
        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer Only',
            'email' => 'viewonly@test.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        // Don't assign any role - no permissions

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->postJson('/api/v1/catalog-carts', [
                'name' => 'Should Fail',
            ]);

        $response->assertStatus(403);
    }
}
