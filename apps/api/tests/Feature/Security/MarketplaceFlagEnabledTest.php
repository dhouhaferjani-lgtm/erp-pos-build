<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tests\Traits\EnablesMarketplaceModule;

/**
 * Marketplace kill-switch — ENABLED half.
 *
 * Counterpart to {@see MarketplaceFlagGatingTest}: flipping
 * `MARKETPLACE_ENABLED=true` must restore the module exactly as it was, with
 * every per-route `can:` gate and `auth:sanctum` still in force. The flag is a
 * kill-switch, never a replacement for authorization.
 */
final class MarketplaceFlagEnabledTest extends TestCase
{
    use EnablesMarketplaceModule;

    public function test_flag_is_on_for_this_class(): void
    {
        $this->assertTrue(config('marketplace.enabled'));
    }

    public function test_marketplace_routes_are_registered_when_enabled(): void
    {
        foreach ([
            'marketplace.listings.index',
            'marketplace.listings.show',
            'marketplace.price-comparison',
            'marketplace.orders.store',
            'marketplace.orders.index',
            'marketplace.orders.show',
            'marketplace.orders.cancel',
            'marketplace.admin.sellers.index',
            'marketplace.admin.sellers.store',
            'marketplace.admin.sellers.update',
            'marketplace.admin.sellers.suspend',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] must be registered while marketplace.enabled is true.");
        }

        $this->assertTrue(Route::has('catalog-carts.marketplace-checkout'));
    }

    public function test_enabled_routes_still_require_authentication(): void
    {
        $this->getJson('/api/v1/marketplace/listings?country=TN')->assertUnauthorized();
        $this->getJson('/api/v1/admin/marketplace/sellers')->assertUnauthorized();
        $this->postJson('/api/v1/catalog-carts/'.fake()->uuid().'/marketplace-checkout', [])
            ->assertUnauthorized();
    }

    public function test_enabled_routes_keep_their_permission_middleware(): void
    {
        $expected = [
            'marketplace.listings.index' => 'can:marketplace.browse',
            'marketplace.price-comparison' => 'can:marketplace.browse',
            'marketplace.orders.store' => 'can:marketplace.order',
            'marketplace.orders.index' => 'can:marketplace.view_orders',
            'marketplace.admin.sellers.store' => 'can:marketplace.admin',
            'marketplace.admin.sellers.suspend' => 'can:marketplace.admin',
            'catalog-carts.marketplace-checkout' => 'can:catalog_cart.marketplace_checkout',
        ];

        foreach ($expected as $name => $middleware) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] must exist while marketplace.enabled is true.");
            $this->assertContains(
                $middleware,
                $route->gatherMiddleware(),
                "Route [{$name}] must keep its [{$middleware}] gate — the flag is a kill-switch, not an authorization replacement.",
            );
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }

    public function test_marketplace_commands_are_scheduled_when_enabled(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('marketplace:delta-sync', $output);
        $this->assertStringContainsString('marketplace:reconcile', $output);
    }
}
