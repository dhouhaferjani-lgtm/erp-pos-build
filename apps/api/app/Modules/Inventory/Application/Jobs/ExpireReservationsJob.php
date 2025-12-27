<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Jobs;

use App\Modules\Inventory\Application\Services\StockReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job that expires old stock reservations.
 *
 * This job should be run periodically (e.g., every 15 minutes or hourly)
 * to find and expire reservations that have passed their expiry time.
 *
 * Expired reservations:
 * - Are marked as released with ReleaseReason::Expired
 * - Have their reserved stock returned to available
 * - Trigger ReservationExpired events for fraud detection
 * - Are flagged as suspicious if high-value
 *
 * Configuration:
 * - Schedule in app/Console/Kernel.php
 * - Recommended frequency: every 15 minutes
 * - Queue: 'default' or 'low-priority'
 */
final class ExpireReservationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(StockReservationService $reservationService): void
    {
        Log::info('ExpireReservationsJob: Starting to expire old reservations');

        try {
            $expiredCount = $reservationService->expireReservations();

            Log::info("ExpireReservationsJob: Expired {$expiredCount} reservations");
        } catch (\Throwable $e) {
            Log::error('ExpireReservationsJob: Failed to expire reservations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
