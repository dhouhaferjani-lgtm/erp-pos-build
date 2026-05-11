<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Broadcasting;

use App\Modules\Product\Domain\Events\ProductCostPriceUpdated;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Infrastructure layer broadcast event wrapper for ProductCostPriceUpdated domain event.
 *
 * This class bridges the gap between domain events and Laravel's broadcasting system,
 * maintaining separation of concerns by keeping broadcasting logic out of the domain layer.
 *
 * @cross-tenant-anchored broadcastOn() constructs a PrivateChannel from the wrapped
 *   ProductCostPriceUpdated domain event's tenantId / companyId / productId properties
 *   (set at domain-event construction time, not from auth() or a request-scoped facade).
 *   ShouldBroadcastNow (synchronous), so queue-context tenant-loss is not in play; the
 *   property-sourced shape is locked here so a future async-promotion does not regress.
 *   Architecture test BroadcastEventTenantContextTest enforces the property-sourcing
 *   pattern via PhpParser scan of the broadcastOn() method body.
 */
class ProductCostPriceUpdatedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly ProductCostPriceUpdated $domainEvent
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * Channel naming convention: tenant.{tenantId}.company.{companyId}.product.{productId}
     * This ensures multi-tenant isolation and fine-grained subscription control.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(
                sprintf(
                    'tenant.%s.company.%s.product.%s',
                    $this->domainEvent->tenantId,
                    $this->domainEvent->companyId,
                    $this->domainEvent->productId
                )
            ),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * Frontend will listen to: '.product.cost-price-updated'
     */
    public function broadcastAs(): string
    {
        return 'product.cost-price-updated';
    }

    /**
     * Get the data to broadcast.
     *
     * Only includes essential data to minimize payload size and avoid sending sensitive information.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = [
            'productId' => $this->domainEvent->productId,
            'productSku' => $this->domainEvent->productSku,
            'oldCostPrice' => $this->domainEvent->oldCostPrice,
            'newCostPrice' => $this->domainEvent->newCostPrice,
            'oldSalePrice' => $this->domainEvent->oldSalePrice,
            'newSalePrice' => $this->domainEvent->newSalePrice,
            'reason' => $this->domainEvent->reason,
            'timestamp' => now()->toIso8601String(),
        ];

        // Only include reference document if present
        if ($this->domainEvent->referenceDocument !== null) {
            $payload['referenceDocument'] = $this->domainEvent->referenceDocument;
        }

        return $payload;
    }
}
