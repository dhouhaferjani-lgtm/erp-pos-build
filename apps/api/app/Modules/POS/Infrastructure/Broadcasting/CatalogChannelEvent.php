<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Bug 1 — coarse catalog-changed signal for POS real-time refresh.
 *
 * Broadcast (ShouldBroadcastNow) on every catalog mutation that could
 * affect the POS view of products: Product create/update/delete,
 * MenuCategory and MenuCategoryItem mutations. The POS frontend debounces
 * incoming events (500ms) and then triggers a full catalog refresh, so the
 * payload deliberately carries no entity-level data — POS reads
 * authoritative state via the REST API, the channel is a "something
 * changed, refetch" signal only.
 *
 * Polling fallback (60s `runFullSync` tick) still covers the gap when
 * the WebSocket is down. WAL + the rest of PR A's stability work keeps
 * the SQL layer responsive for the foreground catalog refetch.
 *
 * @cross-tenant-anchored broadcastOn() constructs a PrivateChannel from
 *   the wrapped tenantId / companyId properties set at event construction
 *   time (caller is an Eloquent observer that reads `$model->tenant_id` /
 *   `$model->company_id` from the just-saved record — no request-scoped
 *   facades, no auth() calls). ShouldBroadcastNow (synchronous), so the
 *   queue-context tenant-loss risk does not apply. Architecture test
 *   BroadcastEventTenantContextTest enforces the property-sourcing
 *   pattern via PhpParser scan of the broadcastOn() method body.
 */
final class CatalogChannelEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $reason,
    ) {}

    /**
     * Channel pattern: tenant.{tenantId}.company.{companyId}.catalog
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(
                sprintf('tenant.%s.company.%s.catalog', $this->tenantId, $this->companyId),
            ),
        ];
    }

    /**
     * Frontend listens to: '.catalog.changed'
     */
    public function broadcastAs(): string
    {
        return 'catalog.changed';
    }

    /**
     * Coarse payload — the frontend debounces and refetches; no entity
     * IDs are needed and would just leak schema details over the wire.
     *
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
