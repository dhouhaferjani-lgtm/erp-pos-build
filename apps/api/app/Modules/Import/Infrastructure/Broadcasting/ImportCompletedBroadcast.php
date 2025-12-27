<?php

declare(strict_types=1);

namespace App\Modules\Import\Infrastructure\Broadcasting;

use App\Modules\Import\Domain\Events\ImportCompleted;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcast event for import completion notification.
 * Sends immediately without queueing (ShouldBroadcastNow).
 */
final class ImportCompletedBroadcast implements ShouldBroadcastNow
{
    public function __construct(
        private readonly ImportCompleted $event
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->event->tenantId}.company.{$this->event->companyId}.imports"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'import.completed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->event->toArray();
    }
}
