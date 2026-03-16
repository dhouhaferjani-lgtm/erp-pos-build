<?php

declare(strict_types=1);

namespace App\Modules\Partner\Infrastructure\Listeners;

use App\Modules\Accounting\Domain\Events\PartnerBalanceUpdated;
use App\Modules\Partner\Infrastructure\Broadcasting\PartnerBalanceUpdatedBroadcast;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Infrastructure layer listener that bridges partner domain events to Laravel broadcasting.
 *
 * Broadcasting failures are caught and logged — they must never break business operations.
 */
class BroadcastPartnerEventsListener
{
    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PartnerBalanceUpdated::class => 'handlePartnerBalanceUpdated',
        ];
    }

    public function handlePartnerBalanceUpdated(PartnerBalanceUpdated $event): void
    {
        try {
            broadcast(new PartnerBalanceUpdatedBroadcast($event));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast partner balance updated', [
                'partner_id' => $event->partnerId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
