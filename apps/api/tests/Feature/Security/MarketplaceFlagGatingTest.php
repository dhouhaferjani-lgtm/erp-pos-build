<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Marketplace kill-switch — DISABLED half (the shipped default).
 *
 * `config('marketplace.enabled')` existed since the module landed but had ZERO
 * readers: the whole Marketplace HTTP surface, the `catalog-carts`
 * marketplace-checkout endpoint and the two scheduled sync commands were live
 * in every tenant regardless of the flag. Two consequences made that a security
 * problem rather than a tidiness one:
 *
 *  1. `marketplace.browse` is seeded to EVERY role by RolesAndPermissionsSeeder,
 *     so the browse/price-comparison surface (deliberately cross-tenant) was
 *     reachable by every authenticated user of every tenant.
 *  2. `/api/v1/admin/marketplace/sellers` is documented on
 *     MarketplaceSellerController as SUPER-ADMIN, fleet-wide seller management,
 *     yet it runs under the ordinary tenant stack behind
 *     `can:marketplace.admin` — a permission the seeder grants to every tenant
 *     admin. Any tenant admin could create and suspend marketplace sellers.
 *
 * This class pins the closed state. The enabled half lives in
 * {@see MarketplaceFlagEnabledTest}. The fleet-wide admin surface still needs a
 * real redesign — tracked in
 * docs/superpowers/tickets/2026-08-05-marketplace-admin-surface-redesign.md.
 */
final class MarketplaceFlagGatingTest extends TestCase
{
    public function test_marketplace_is_disabled_by_default(): void
    {
        $this->assertFalse(
            config('marketplace.enabled'),
            'MARKETPLACE_ENABLED must default to false — the module is unreleased and its admin surface is not tenant-safe.',
        );
    }

    public function test_marketplace_routes_are_not_registered_when_disabled(): void
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
            $this->assertFalse(
                Route::has($name),
                "Route [{$name}] must not be registered while marketplace.enabled is false.",
            );
        }
    }

    public function test_marketplace_endpoints_return_404_when_disabled(): void
    {
        $this->getJson('/api/v1/marketplace/listings?country=TN')->assertNotFound();
        $this->getJson('/api/v1/marketplace/price-comparison?article_number=X')->assertNotFound();
        $this->postJson('/api/v1/marketplace/orders', [])->assertNotFound();
        $this->getJson('/api/v1/admin/marketplace/sellers')->assertNotFound();
        $this->postJson('/api/v1/admin/marketplace/sellers', [])->assertNotFound();
    }

    public function test_cart_marketplace_checkout_route_is_not_registered_when_disabled(): void
    {
        $this->assertFalse(
            Route::has('catalog-carts.marketplace-checkout'),
            'catalog-carts.marketplace-checkout is a marketplace surface and must follow the same flag.',
        );

        $this->postJson('/api/v1/catalog-carts/'.fake()->uuid().'/marketplace-checkout', [])
            ->assertNotFound();
    }

    public function test_rest_of_the_cart_surface_stays_registered_when_marketplace_is_disabled(): void
    {
        // Guards against gating the whole Cart route group by accident: only the
        // marketplace-checkout endpoint is marketplace-owned.
        foreach ([
            'catalog-carts.index',
            'catalog-carts.store',
            'catalog-carts.show',
            'catalog-carts.update',
            'catalog-carts.destroy',
            'catalog-carts.items.store',
            'catalog-carts.items.update',
            'catalog-carts.items.destroy',
            'catalog-carts.convert',
        ] as $name) {
            $this->assertTrue(
                Route::has($name),
                "Route [{$name}] is not marketplace-owned and must remain registered.",
            );
        }
    }

    public function test_marketplace_commands_are_not_scheduled_when_disabled(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringNotContainsString('marketplace:delta-sync', $output);
        $this->assertStringNotContainsString('marketplace:reconcile', $output);
    }

    public function test_marketplace_commands_stay_manually_invocable_when_disabled(): void
    {
        // Deliberate: the flag gates the *automatic* fan-out and the HTTP
        // surface, not operator-driven maintenance. An operator must still be
        // able to run a reconciliation by hand while the module is dark.
        $commands = Artisan::all();

        $this->assertArrayHasKey('marketplace:delta-sync', $commands);
        $this->assertArrayHasKey('marketplace:reconcile', $commands);
    }
}
