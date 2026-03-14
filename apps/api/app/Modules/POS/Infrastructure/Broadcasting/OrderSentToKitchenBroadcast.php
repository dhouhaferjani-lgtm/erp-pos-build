<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Broadcasting;

use App\Modules\POS\Domain\Order;
use App\Modules\POS\Presentation\Resources\OrderResource;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcast event when an order is sent to the kitchen.
 * Sends immediately without queueing (ShouldBroadcastNow).
 */
final class OrderSentToKitchenBroadcast implements ShouldBroadcastNow
{
    private readonly Order $order;

    public function __construct(
        private readonly string $orderId,
        private readonly string $tenantId,
        private readonly string $companyId,
    ) {
        /** @var Order $order */
        $order = Order::with(['lines', 'table.floor'])->findOrFail($this->orderId);
        $this->order = $order;
    }

    /**
     * @return array<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.company.{$this->companyId}.pos.kitchen"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.sent_to_kitchen';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order' => (new OrderResource($this->order))->resolve(),
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
