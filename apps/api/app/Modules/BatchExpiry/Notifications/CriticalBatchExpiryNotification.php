<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Notifications;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Notification sent to company admins when batches are within 7 days of expiry.
 */
class CriticalBatchExpiryNotification extends Notification
{
    /**
     * @param  Collection<int, Batch>  $batches
     */
    public function __construct(
        private readonly Collection $batches,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'critical_batch_expiry',
            'batch_count' => $this->batches->count(),
            'batches' => $this->batches->map(fn (Batch $batch): array => [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'product_name' => $batch->product->name ?? 'Unknown',
                'product_id' => $batch->product_id,
                'expiry_date' => $batch->expiry_date->toDateString(),
                'days_until_expiry' => $batch->daysUntilExpiry(),
            ])->toArray(),
            'message' => $this->batches->count() === 1
                ? "1 batch is expiring within 7 days"
                : "{$this->batches->count()} batches are expiring within 7 days",
        ];
    }
}
