<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Broadcasting;

use App\Modules\POS\Domain\Events\TerminalActivated;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcast event for terminal activation notification.
 * Sends immediately without queueing (ShouldBroadcastNow).
 */
final class TerminalActivatedBroadcast implements ShouldBroadcastNow
{
    public function __construct(
        private readonly TerminalActivated $event
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->event->tenantId}.company.{$this->event->companyId}.pos.terminal.{$this->event->terminalId}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'terminal.activated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'terminalId' => $this->event->terminalId,
            'terminalCode' => $this->event->terminalCode,
            'terminalName' => $this->event->terminalName,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
