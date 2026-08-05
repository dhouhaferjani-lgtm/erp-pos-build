<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Vertical;
use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Marketplace kill-switch — the Cart → Marketplace APPLICATION path.
 *
 * De-registering the Marketplace HTTP surface (see {@see MarketplaceFlagGatingTest})
 * is not sufficient on its own: `catalog-carts.items.store` is a procurement
 * endpoint that must stay live while the module is dark, yet its payload used to
 * accept `source=marketplace` + `marketplace_listing_id`, which
 * CartService::addItem() routes straight into MarketplaceListing::findOrFail()
 * and MarketplaceOrderService::reserveForCart() — creating a real stock
 * reservation and burning the `marketplace.anti_abuse` counter while the module
 * is supposedly off.
 *
 * The guard lives in the VALIDATION layer on purpose: the marketplace branch of
 * the service must be unreachable over HTTP, not merely fail late, and the
 * service itself stays callable in-process (seeders, the enabled flow, and
 * tests/Feature/Marketplace/StockReservationTest all drive it directly).
 */
final class MarketplaceCartItemGatingTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private CatalogCart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cart Gating Tenant',
            'slug' => 'cart-gating-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cart Gating Co',
            'legal_name' => 'Cart Gating Co LLC',
            'tax_id' => 'CARTGATE1',
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
            'name' => 'Cart Gating User',
            'email' => 'cart-gating@test.com',
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

        $this->cart = CatalogCart::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_the_flag_is_off_for_this_class(): void
    {
        $this->assertFalse(config('marketplace.enabled'));
    }

    public function test_items_store_rejects_a_marketplace_listing_id_while_the_module_is_disabled(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$this->cart->id}/items", [
                'source' => 'catalog',
                'article_name' => 'Smuggled Marketplace Part',
                'quantity' => '1',
                'marketplace_listing_id' => fake()->uuid(),
            ]);

        $this->assertApiValidationErrors($response, ['marketplace_listing_id']);
        $this->assertDatabaseCount('catalog_cart_items', 0);
    }

    public function test_items_store_rejects_source_marketplace_while_the_module_is_disabled(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$this->cart->id}/items", [
                'source' => 'marketplace',
                'article_name' => 'Smuggled Marketplace Part',
                'quantity' => '1',
            ]);

        $this->assertApiValidationErrors($response, ['source']);
        $this->assertDatabaseCount('catalog_cart_items', 0);
    }

    public function test_no_stock_reservation_is_created_by_a_rejected_marketplace_payload(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$this->cart->id}/items", [
                'source' => 'marketplace',
                'article_name' => 'Smuggled Marketplace Part',
                'quantity' => '2',
                'marketplace_listing_id' => fake()->uuid(),
            ])
            ->assertUnprocessable();

        // The marketplace branch of CartService::addItem() must never have run:
        // it would have created a stock reservation (and consumed the
        // anti-abuse counter) before failing.
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_ordinary_catalog_items_are_unaffected_while_the_module_is_disabled(): void
    {
        // The kill-switch must not break procurement: catalog/manual cart items
        // are the reason `catalog-carts.items.store` stays registered at all.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$this->cart->id}/items", [
                'source' => 'catalog',
                'article_name' => 'Brake Pad',
                'quantity' => '2',
                'unit_price' => '19.500',
                'currency' => 'TND',
            ]);

        $response->assertCreated();
        $this->assertSame(1, CatalogCartItem::query()->where('cart_id', $this->cart->id)->count());
    }
}
