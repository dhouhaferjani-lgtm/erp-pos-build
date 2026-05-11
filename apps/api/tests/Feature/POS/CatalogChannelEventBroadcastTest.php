<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Menu\Domain\Entities\Menu;
use App\Modules\Menu\Domain\Entities\MenuCategory;
use App\Modules\Menu\Domain\Entities\MenuCategoryItem;
use App\Modules\POS\Infrastructure\Broadcasting\CatalogChannelEvent;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Bug 1 — assert CatalogChannelEvent broadcasts on catalog-model mutations
 * (Product / Menu / MenuCategory) and that the channel name + payload
 * match the contract the POS frontend consumes.
 */
final class CatalogChannelEventBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_name_matches_pattern(): void
    {
        $event = new CatalogChannelEvent(
            tenantId: 'tenant-abc',
            companyId: 'company-xyz',
            reason: 'Product.saved',
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame(
            'private-tenant.tenant-abc.company.company-xyz.catalog',
            $channels[0]->name,
        );
    }

    public function test_broadcast_as_returns_catalog_changed(): void
    {
        $event = new CatalogChannelEvent('t', 'c', 'r');
        $this->assertSame('catalog.changed', $event->broadcastAs());
    }

    public function test_broadcast_with_carries_reason_and_timestamp_only(): void
    {
        $event = new CatalogChannelEvent('t', 'c', 'MenuCategory.deleted');
        $payload = $event->broadcastWith();

        $this->assertSame('MenuCategory.deleted', $payload['reason']);
        $this->assertArrayHasKey('timestamp', $payload);
        // Coarse contract: no entity IDs leak via the wire payload.
        $this->assertCount(2, $payload);
    }

    public function test_product_save_dispatches_catalog_channel_event(): void
    {
        Event::fake([CatalogChannelEvent::class]);

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && str_starts_with($e->reason, 'Product.'),
        );
    }

    public function test_product_delete_dispatches_catalog_channel_event(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        Event::fake([CatalogChannelEvent::class]);

        $product->delete();

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && $e->reason === 'Product.deleted',
        );
    }

    public function test_menu_save_dispatches_catalog_channel_event(): void
    {
        Event::fake([CatalogChannelEvent::class]);

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        Menu::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Lunch Menu',
            'is_active' => true,
        ]);

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && str_starts_with($e->reason, 'Menu.'),
        );
    }

    public function test_menu_category_save_dispatches_catalog_channel_event_with_resolved_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $menu = Menu::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Lunch Menu',
            'is_active' => true,
        ]);

        Event::fake([CatalogChannelEvent::class]);

        MenuCategory::create([
            'menu_id' => $menu->id,
            'name' => 'Starters',
            'display_order' => 1,
            'is_active' => true,
        ]);

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && str_starts_with($e->reason, 'MenuCategory.'),
        );
    }

    public function test_sync_items_endpoint_dispatches_catalog_channel_event_despite_mass_delete(): void
    {
        // Codex r1 P2 — Eloquent's where()->delete() is a mass delete and
        // bypasses model events. The controller now emits an explicit
        // CatalogChannelEvent after the bulk rewrite so the POS picks up
        // the change immediately.
        [$tenant, $company, $user, $menu, $category] = $this->scaffoldMenuStack();

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        Sanctum::actingAs($user);
        Event::fake([CatalogChannelEvent::class]);

        $response = $this->withHeaders(['X-Company-Id' => $company->id])
            ->putJson("/api/v1/menu-categories/{$category->id}/items", [
                'items' => [
                    [
                        'sellable_type' => 'product',
                        'sellable_id' => $product->id,
                        'display_order' => 1,
                        'is_available' => true,
                    ],
                ],
            ]);

        $response->assertStatus(200);

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && $e->reason === 'MenuCategoryItem.sync',
        );
    }

    public function test_composite_item_save_dispatches_catalog_channel_event(): void
    {
        // Codex r2 P2 closure — composite items surface in the POS
        // active-menu payload for Menu tenants. CompositeItem changes
        // must trigger catalog.changed too.
        Event::fake([CatalogChannelEvent::class]);

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        CompositeItem::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CAPPUCCINO',
            'name' => 'Cappuccino',
            'base_price' => '5.00',
        ]);

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && str_starts_with($e->reason, 'CompositeItem.'),
        );
    }

    public function test_modifier_group_save_dispatches_catalog_channel_event(): void
    {
        // Codex r3 P2 closure — ModifierGroup is serialized into the POS
        // active-menu payload via composite items' modifier groups.
        Event::fake([CatalogChannelEvent::class]);

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        ModifierGroup::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'MG-1',
            'name' => 'Size',
            'selection_type' => 'single',
        ]);

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && str_starts_with($e->reason, 'ModifierGroup.'),
        );
    }

    public function test_modifier_save_dispatches_catalog_channel_event_with_resolved_tenant(): void
    {
        // Codex r3 P2 closure — Modifier rows are relation-resolved via
        // ModifierGroup. Event::listen subscription extracts the parent
        // group's tenant + company before broadcasting.
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $group = ModifierGroup::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'MG-1',
            'name' => 'Size',
            'selection_type' => 'single',
        ]);

        Event::fake([CatalogChannelEvent::class]);

        Modifier::create([
            'modifier_group_id' => $group->id,
            'code' => 'MOD-1',
            'name' => 'Large',
            'price_adjustment' => 0,
        ]);

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && str_starts_with($e->reason, 'Modifier.'),
        );
    }

    public function test_remove_item_endpoint_dispatches_catalog_channel_event_despite_mass_delete(): void
    {
        [$tenant, $company, $user, $menu, $category] = $this->scaffoldMenuStack();

        $item = MenuCategoryItem::create([
            'menu_category_id' => $category->id,
            'product_id' => null,
            'composite_item_id' => null,
            'display_order' => 1,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);
        Event::fake([CatalogChannelEvent::class]);

        $response = $this->withHeaders(['X-Company-Id' => $company->id])
            ->deleteJson("/api/v1/menu-categories/{$category->id}/items/{$item->id}");

        $response->assertStatus(204);

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && $e->reason === 'MenuCategoryItem.removed',
        );
    }

    public function test_modifier_group_assign_endpoint_dispatches_catalog_channel_event_despite_pivot_write(): void
    {
        // Codex r4 P2 closure — syncWithoutDetaching() on a pivot table
        // doesn't fire model events on either side, so the observer
        // never sees the change. The controller now broadcasts
        // explicitly after the pivot write.
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Owner,
            'is_active' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Permission::findOrCreate('composite-items.update', 'sanctum');
        $user->givePermissionTo('composite-items.update');

        $compositeItem = CompositeItem::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CAP',
            'name' => 'Cappuccino',
            'base_price' => '5.00',
        ]);
        $group = ModifierGroup::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'SIZE',
            'name' => 'Size',
            'selection_type' => 'single',
        ]);

        Sanctum::actingAs($user);
        Event::fake([CatalogChannelEvent::class]);

        $response = $this->withHeaders(['X-Company-Id' => $company->id])
            ->postJson("/api/v1/composite-items/{$compositeItem->id}/modifier-groups", [
                'modifier_group_id' => $group->id,
                'display_order' => 0,
            ]);

        $response->assertStatus(200);

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && $e->reason === 'CompositeItemModifierGroup.assigned',
        );
    }

    public function test_modifier_group_remove_endpoint_dispatches_catalog_channel_event_despite_pivot_write(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Owner,
            'is_active' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Permission::findOrCreate('composite-items.update', 'sanctum');
        $user->givePermissionTo('composite-items.update');

        $compositeItem = CompositeItem::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CAP',
            'name' => 'Cappuccino',
            'base_price' => '5.00',
        ]);
        $group = ModifierGroup::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'SIZE',
            'name' => 'Size',
            'selection_type' => 'single',
        ]);
        $compositeItem->modifierGroups()->syncWithoutDetaching([$group->id => ['display_order' => 0]]);

        Sanctum::actingAs($user);
        Event::fake([CatalogChannelEvent::class]);

        $response = $this->withHeaders(['X-Company-Id' => $company->id])
            ->deleteJson("/api/v1/composite-items/{$compositeItem->id}/modifier-groups/{$group->id}");

        $response->assertStatus(204);

        Event::assertDispatched(
            CatalogChannelEvent::class,
            fn (CatalogChannelEvent $e): bool => $e->tenantId === $tenant->id
                && $e->companyId === $company->id
                && $e->reason === 'CompositeItemModifierGroup.removed',
        );
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: User, 3: Menu, 4: MenuCategory}
     */
    private function scaffoldMenuStack(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Owner,
            'is_active' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Permission::findOrCreate('menus.manage', 'sanctum');
        $user->givePermissionTo('menus.manage');

        $menu = Menu::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Lunch Menu',
            'is_active' => true,
        ]);
        $category = MenuCategory::create([
            'menu_id' => $menu->id,
            'name' => 'Starters',
            'display_order' => 1,
            'is_active' => true,
        ]);

        return [$tenant, $company, $user, $menu, $category];
    }
}
