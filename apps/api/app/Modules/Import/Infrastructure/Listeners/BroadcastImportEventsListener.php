<?php

declare(strict_types=1);

namespace App\Modules\Import\Infrastructure\Listeners;

use App\Modules\Import\Domain\Events\ImportCompleted;
use App\Modules\Import\Domain\Events\ImportProgressUpdated;
use App\Modules\Import\Infrastructure\Broadcasting\ImportCompletedBroadcast;
use App\Modules\Import\Infrastructure\Broadcasting\ImportProgressBroadcast;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Event subscriber that listens for import domain events
 * and broadcasts them via WebSocket.
 */
final class BroadcastImportEventsListener
{
    /**
     * Handle the import progress updated event.
     */
    public function handleProgressUpdated(ImportProgressUpdated $event): void
    {
        try {
            broadcast(new ImportProgressBroadcast($event));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast import progress', [
                'import_job_id' => $event->importJobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the import completed event.
     */
    public function handleCompleted(ImportCompleted $event): void
    {
        try {
            broadcast(new ImportCompletedBroadcast($event));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast import completed', [
                'import_job_id' => $event->importJobId,
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
            ImportProgressUpdated::class => 'handleProgressUpdated',
            ImportCompleted::class => 'handleCompleted',
        ];
    }
}
