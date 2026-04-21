<?php

declare(strict_types=1);

namespace App\Modules\Partner\Infrastructure\Broadcasting;

use App\Modules\Accounting\Domain\Events\PartnerBalanceUpdated;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Infrastructure layer broadcast event for partner balance updates.
 *
 * Uses a company-level channel (not per-partner) so the list page
 * receives all partner balance updates without subscribing to N channels.
 */
class PartnerBalanceUpdatedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly PartnerBalanceUpdated $domainEvent
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(
                sprintf(
                    'tenant.%s.company.%s.partners',
                    $this->domainEvent->tenantId,
                    $this->domainEvent->companyId
                )
            ),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * Frontend will listen to: '.partner.balance-updated'
     */
    public function broadcastAs(): string
    {
        return 'partner.balance-updated';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'partnerId' => $this->domainEvent->partnerId,
            'receivableBalance' => $this->domainEvent->receivableBalance,
            'creditBalance' => $this->domainEvent->creditBalance,
            'payableBalance' => $this->domainEvent->payableBalance,
            'netBalance' => $this->domainEvent->netBalance,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
