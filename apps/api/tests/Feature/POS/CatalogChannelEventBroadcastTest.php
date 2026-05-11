<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Menu\Domain\Entities\Menu;
use App\Modules\Menu\Domain\Entities\MenuCategory;
use App\Modules\POS\Infrastructure\Broadcasting\CatalogChannelEvent;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
}
