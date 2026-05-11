<?php

declare(strict_types=1);

namespace App\Modules\Import\Infrastructure\Broadcasting;

use App\Modules\Import\Domain\Events\ImportProgressUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcast event for real-time import progress updates.
 * Sends immediately without queueing (ShouldBroadcastNow).
 *
 * @cross-tenant-anchored broadcastOn() constructs the imports PrivateChannel from
 *   $this->event->tenantId + $this->event->companyId (the wrapped ImportProgressUpdated
 *   domain event carries them at construction). ShouldBroadcastNow (synchronous).
 *   Architecture test BroadcastEventTenantContextTest enforces the property-sourcing
 *   pattern.
 */
final class ImportProgressBroadcast implements ShouldBroadcastNow
{
    public function __construct(
        private readonly ImportProgressUpdated $event
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
        return 'import.progress';
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
