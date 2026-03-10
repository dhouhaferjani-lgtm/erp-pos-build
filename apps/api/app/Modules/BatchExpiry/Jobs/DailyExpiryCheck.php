<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Jobs;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Notifications\CriticalBatchExpiryNotification;
use App\Modules\Identity\Domain\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Daily batch expiry check job.
 *
 * This job runs daily to:
 * 1. Mark expired batches as expired (is_expired = true)
 * 2. Identify batches approaching expiry (for alerts)
 * 3. Send notifications to company admins
 * 4. Log expiry events for audit trail
 *
 * Schedule: Daily at 1:00 AM
 */
class DailyExpiryCheck implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting daily batch expiry check');

        $today = now()->startOfDay();

        // Mark expired batches
        $expiredCount = $this->markExpiredBatches($today);

        // Get batches approaching expiry (within 30 days)
        $approachingExpiry = $this->getBatchesApproachingExpiry($today, 30);

        // Get critical batches (within 7 days)
        $criticalBatches = $this->getBatchesApproachingExpiry($today, 7);

        // Log summary
        Log::info('Daily batch expiry check completed', [
            'expired_marked' => $expiredCount,
            'approaching_expiry_30d' => $approachingExpiry->count(),
            'critical_7d' => $criticalBatches->count(),
        ]);

        // TODO: Send notifications to company admins
        // This can be implemented based on company notification preferences
        // Example: Send email/SMS for critical batches
        if ($criticalBatches->isNotEmpty()) {
            $this->notifyCompaniesOfCriticalBatches($criticalBatches);
        }
    }

    /**
     * Mark all expired batches as expired.
     *
     * Updates batches where expiry_date < today and is_expired = false.
     *
     * @return int Number of batches marked as expired
     */
    private function markExpiredBatches(\Illuminate\Support\Carbon $today): int
    {
        return DB::transaction(function () use ($today): int {
            $expiredBatches = Batch::where('expiry_date', '<', $today)
                ->where('is_expired', false)
                ->where('is_active', true)
                ->get();

            $count = 0;
            foreach ($expiredBatches as $batch) {
                $batch->update(['is_expired' => true]);

                Log::warning('Batch marked as expired', [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'product_id' => $batch->product_id,
                    'company_id' => $batch->company_id,
                    'expiry_date' => $batch->expiry_date->toDateString(),
                ]);

                $count++;
            }

            return $count;
        });
    }

    /**
     * Get batches approaching expiry within threshold.
     *
     * @param  int  $daysThreshold  Number of days threshold
     * @return \Illuminate\Support\Collection<int, Batch>
     */
    private function getBatchesApproachingExpiry(\Illuminate\Support\Carbon $today, int $daysThreshold): \Illuminate\Support\Collection
    {
        $thresholdDate = $today->copy()->addDays($daysThreshold);

        return Batch::whereBetween('expiry_date', [$today, $thresholdDate])
            ->where('is_active', true)
            ->where('is_recalled', false)
            ->where('is_expired', false)
            ->with(['product', 'company'])
            ->get();
    }

    /**
     * Notify companies about batches in critical expiry window.
     *
     * Groups batches by company and sends notifications.
     *
     * @param  \Illuminate\Support\Collection<int, Batch>  $criticalBatches
     */
    private function notifyCompaniesOfCriticalBatches(\Illuminate\Support\Collection $criticalBatches): void
    {
        // Group batches by company
        $batchesByCompany = $criticalBatches->groupBy('company_id');

        foreach ($batchesByCompany as $companyId => $batches) {
            $firstBatch = $batches->first();
            $company = $firstBatch?->company;

            Log::info('Critical batches found for company', [
                'company_id' => $companyId,
                'company_name' => $company->name ?? 'Unknown',
                'batch_count' => $batches->count(),
                'batches' => $batches->map(fn ($b) => [
                    'batch_number' => $b->batch_number,
                    'product_name' => $b->product->name ?? 'Unknown',
                    'expiry_date' => $b->expiry_date->toDateString(),
                    'days_until_expiry' => $b->daysUntilExpiry(),
                ])->toArray(),
            ]);

            $admins = User::whereRaw('company_id = ?', [$companyId])
                ->permission('batches.view')
                ->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new CriticalBatchExpiryNotification($batches));

                Log::info('Sent critical batch expiry notifications', [
                    'company_id' => $companyId,
                    'admin_count' => $admins->count(),
                    'batch_count' => $batches->count(),
                ]);
            }
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900]; // Retry after 1 min, 5 min, 15 min
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('Daily batch expiry check failed', [
            'exception' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        // TODO: Send alert to system administrators
    }
}
