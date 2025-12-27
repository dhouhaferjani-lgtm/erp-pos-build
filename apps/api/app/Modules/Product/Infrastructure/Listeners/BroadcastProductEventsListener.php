<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Listeners;

use App\Modules\Product\Domain\Events\ProductCostPriceUpdated;
use App\Modules\Product\Infrastructure\Broadcasting\ProductCostPriceUpdatedBroadcast;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Infrastructure layer listener that bridges domain events to Laravel broadcasting.
 *
 * This class subscribes to domain events and dispatches corresponding broadcast events,
 * maintaining the separation between domain logic and broadcasting infrastructure.
 */
class BroadcastProductEventsListener
{
    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ProductCostPriceUpdated::class => 'handleProductCostUpdated',
        ];
    }

    /**
     * Handle the ProductCostPriceUpdated domain event.
     *
     * Dispatches a broadcast event to notify connected clients via WebSocket.
     */
    public function handleProductCostUpdated(ProductCostPriceUpdated $event): void
    {
        event(new ProductCostPriceUpdatedBroadcast($event));
    }
}
