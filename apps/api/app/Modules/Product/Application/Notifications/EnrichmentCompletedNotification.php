<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Notifications;

use App\Modules\Product\Domain\EnrichmentResult;
use Illuminate\Notifications\Notification;

/**
 * Notification sent to company users when product enrichment completes.
 */
class EnrichmentCompletedNotification extends Notification
{
    public function __construct(
        private readonly EnrichmentResult $enrichmentResult,
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
        $product = $this->enrichmentResult->product;

        return [
            'type' => 'enrichment_completed',
            'enrichment_result_id' => $this->enrichmentResult->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'enrichment_quality' => $this->enrichmentResult->enrichment_quality,
            'has_barcode_assigned' => $this->enrichmentResult->assigned_barcode !== null,
            'assigned_barcode' => $this->enrichmentResult->assigned_barcode,
            'message' => "Product \"{$product->name}\" enrichment completed with {$this->enrichmentResult->enrichment_quality} quality.",
        ];
    }
}
