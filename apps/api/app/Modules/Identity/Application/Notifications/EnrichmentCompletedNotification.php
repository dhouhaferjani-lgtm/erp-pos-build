<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Notification sent to company users when product enrichment completes.
 *
 * Uses scalar data from the shared event rather than importing Product module models.
 */
final class EnrichmentCompletedNotification extends Notification
{
    public function __construct(
        private readonly string $enrichmentResultId,
        private readonly string $productId,
        private readonly string $productName,
        private readonly string $enrichmentQuality,
        private readonly ?string $assignedBarcode,
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
            'type' => 'enrichment_completed',
            'enrichment_result_id' => $this->enrichmentResultId,
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'enrichment_quality' => $this->enrichmentQuality,
            'has_barcode_assigned' => $this->assignedBarcode !== null,
            'assigned_barcode' => $this->assignedBarcode,
            'message' => "Product \"{$this->productName}\" enrichment completed with {$this->enrichmentQuality} quality.",
        ];
    }
}
