<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcast event when an order is fully ready (all lines Ready).
 * Sends immediately without queueing (ShouldBroadcastNow).
 */
final class OrderReadyBroadcast implements ShouldBroadcastNow
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $companyId,
        private readonly string $orderId,
    ) {}

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
        return 'order.ready';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'orderId' => $this->orderId,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
