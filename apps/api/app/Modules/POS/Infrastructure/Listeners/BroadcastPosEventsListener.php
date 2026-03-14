<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Listeners;

use App\Modules\POS\Domain\Events\OrderLineStatusChanged;
use App\Modules\POS\Domain\Events\OrderReady;
use App\Modules\POS\Domain\Events\OrderSentToKitchen;
use App\Modules\POS\Domain\Events\TerminalActivated;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Infrastructure\Broadcasting\OrderLineStatusChangedBroadcast;
use App\Modules\POS\Infrastructure\Broadcasting\OrderReadyBroadcast;
use App\Modules\POS\Infrastructure\Broadcasting\OrderSentToKitchenBroadcast;
use App\Modules\POS\Infrastructure\Broadcasting\TerminalActivatedBroadcast;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Event subscriber that listens for POS domain events
 * and broadcasts them via WebSocket.
 */
final class BroadcastPosEventsListener
{
    public function handleTerminalActivated(TerminalActivated $event): void
    {
        try {
            broadcast(new TerminalActivatedBroadcast($event));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast terminal activated', [
                'terminal_id' => $event->terminalId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handleOrderSentToKitchen(OrderSentToKitchen $event): void
    {
        try {
            /** @var Order|null $order */
            $order = Order::find($event->orderId);

            if ($order === null) {
                return;
            }

            broadcast(new OrderSentToKitchenBroadcast(
                orderId: $event->orderId,
                tenantId: $order->tenant_id,
                companyId: $order->company_id,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast order sent to kitchen', [
                'order_id' => $event->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handleOrderLineStatusChanged(OrderLineStatusChanged $event): void
    {
        try {
            /** @var Order|null $order */
            $order = Order::find($event->orderId);

            if ($order === null) {
                return;
            }

            broadcast(new OrderLineStatusChangedBroadcast(
                tenantId: $order->tenant_id,
                companyId: $order->company_id,
                orderId: $event->orderId,
                lineId: $event->lineId,
                fromStatus: $event->fromStatus,
                toStatus: $event->toStatus,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast order line status changed', [
                'order_id' => $event->orderId,
                'line_id' => $event->lineId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handleOrderReady(OrderReady $event): void
    {
        try {
            /** @var Order|null $order */
            $order = Order::find($event->orderId);

            if ($order === null) {
                return;
            }

            broadcast(new OrderReadyBroadcast(
                tenantId: $order->tenant_id,
                companyId: $order->company_id,
                orderId: $event->orderId,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast order ready', [
                'order_id' => $event->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            TerminalActivated::class => 'handleTerminalActivated',
            OrderSentToKitchen::class => 'handleOrderSentToKitchen',
            OrderLineStatusChanged::class => 'handleOrderLineStatusChanged',
            OrderReady::class => 'handleOrderReady',
        ];
    }
}
