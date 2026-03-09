<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Listeners;

use App\Modules\POS\Domain\Events\TerminalActivated;
use App\Modules\POS\Infrastructure\Broadcasting\TerminalActivatedBroadcast;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Event subscriber that listens for POS domain events
 * and broadcasts them via WebSocket.
 */
final class BroadcastPosEventsListener
{
    /**
     * Handle the terminal activated event.
     */
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

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            TerminalActivated::class => 'handleTerminalActivated',
        ];
    }
}
