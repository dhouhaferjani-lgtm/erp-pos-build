<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Listeners;

use App\Modules\Identity\Application\Notifications\EnrichmentCompletedNotification;
use App\Modules\Identity\Domain\User;
use App\Shared\Events\EnrichmentResultReadyEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class SendEnrichmentNotificationListener
{
    public function handle(EnrichmentResultReadyEvent $event): void
    {
        $users = User::query()
            ->whereHas('companyMemberships', function (\Illuminate\Database\Eloquent\Builder $query) use ($event): void {
                $query->whereRaw('company_id = ?', [$event->companyId])
                    ->whereRaw('status = ?', ['active']);
            })
            ->permission('enrichment.view')
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new EnrichmentCompletedNotification(
            enrichmentResultId: $event->enrichmentResultId,
            productId: $event->productId,
            productName: $event->productName,
            enrichmentQuality: $event->enrichmentQuality,
            assignedBarcode: $event->assignedBarcode,
        ));

        Log::info('Sent enrichment completed notifications', [
            'product_id' => $event->productId,
            'company_id' => $event->companyId,
            'user_count' => $users->count(),
        ]);
    }
}
