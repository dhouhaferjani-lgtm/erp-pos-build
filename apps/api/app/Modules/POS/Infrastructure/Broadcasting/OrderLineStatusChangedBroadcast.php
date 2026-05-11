<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcast event when an order line status changes.
 * Sends immediately without queueing (ShouldBroadcastNow).
 *
 * @cross-tenant-anchored broadcastOn() constructs the kitchen PrivateChannel from
 *   constructor-provided readonly $tenantId + $companyId. ShouldBroadcastNow
 *   (synchronous). Architecture test BroadcastEventTenantContextTest enforces the
 *   property-sourcing pattern.
 */
final class OrderLineStatusChangedBroadcast implements ShouldBroadcastNow
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $companyId,
        private readonly string $orderId,
        private readonly string $lineId,
        private readonly string $fromStatus,
        private readonly string $toStatus,
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
        return 'order.line_status_changed';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'orderId' => $this->orderId,
            'lineId' => $this->lineId,
            'fromStatus' => $this->fromStatus,
            'toStatus' => $this->toStatus,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
