<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Product\Domain\Events\ProductCostPriceUpdated;
use App\Modules\Product\Infrastructure\Broadcasting\ProductCostPriceUpdatedBroadcast;
use App\Modules\Product\Infrastructure\Listeners\BroadcastProductEventsListener;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastProductEventsListenerTest extends TestCase
{
    public function test_domain_event_triggers_broadcast_event(): void
    {
        // Fake only the broadcast event to prevent actual broadcasting
        // but allow the domain event and listener to work normally
        Event::fake([ProductCostPriceUpdatedBroadcast::class]);

        // Manually trigger the listener since Event::fake prevents automatic triggering
        $domainEvent = new ProductCostPriceUpdated(
            productId: 'prod-123',
            tenantId: 'tenant-abc',
            companyId: 'company-xyz',
            productSku: 'SKU-001',
            oldCostPrice: '10.00',
            newCostPrice: '12.00',
            oldSalePrice: '15.00',
            newSalePrice: '18.00',
            reason: 'purchase_receipt',
            referenceDocument: 'PO-001',
        );

        $listener = new BroadcastProductEventsListener;
        $listener->handleProductCostUpdated($domainEvent);

        // Assert broadcast event was dispatched
        Event::assertDispatched(ProductCostPriceUpdatedBroadcast::class, function ($event) {
            return $event->domainEvent->productId === 'prod-123'
                && $event->domainEvent->newCostPrice === '12.00';
        });
    }

    public function test_broadcast_event_contains_domain_event_data(): void
    {
        Event::fake([ProductCostPriceUpdatedBroadcast::class]);

        $domainEvent = new ProductCostPriceUpdated(
            productId: 'prod-456',
            tenantId: 'tenant-def',
            companyId: 'company-abc',
            productSku: 'SKU-002',
            oldCostPrice: '20.00',
            newCostPrice: '25.00',
            oldSalePrice: '30.00',
            newSalePrice: '37.50',
            reason: 'manual_adjustment',
            referenceDocument: null,
        );

        $listener = new BroadcastProductEventsListener;
        $listener->handleProductCostUpdated($domainEvent);

        Event::assertDispatched(ProductCostPriceUpdatedBroadcast::class, function ($event) use ($domainEvent) {
            return $event->domainEvent->productId === $domainEvent->productId
                && $event->domainEvent->newCostPrice === $domainEvent->newCostPrice;
        });
    }
}
