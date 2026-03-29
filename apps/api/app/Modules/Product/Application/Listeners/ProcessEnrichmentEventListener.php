<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Listeners;

use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Product\Domain\Product;
use App\Shared\Enums\EnrichmentStatus;
use App\Shared\Events\EnrichmentResultReadyEvent;
use Illuminate\Support\Facades\Log;

final class ProcessEnrichmentEventListener
{
    public function __construct(
        private readonly EnrichmentReviewService $enrichmentReviewService,
    ) {}

    public function handle(EnrichmentWebhookReceived $event): void
    {
        $product = Product::where('platform_submission_id', $event->trackingId)->first();

        if ($product === null) {
            Log::warning('Enrichment webhook received for unknown tracking ID', [
                'tracking_id' => $event->trackingId,
                'status' => $event->status,
            ]);

            return;
        }

        // Map platform status to local enrichment status
        $enrichmentStatus = EnrichmentStatus::fromPlatformStatus($event->status);
        $product->update(['enrichment_status' => $enrichmentStatus]);

        // For terminal statuses, fetch and store full enrichment result
        $terminalStatuses = [
            EnrichmentStatus::Completed,
            EnrichmentStatus::Failed,
            EnrichmentStatus::NotEnrichable,
        ];

        if (in_array($enrichmentStatus, $terminalStatuses, true)) {
            $enrichmentResult = $this->enrichmentReviewService->fetchAndStore(
                $event->trackingId,
                $product,
            );

            // For completed enrichments, dispatch event for notification delivery
            if ($enrichmentStatus === EnrichmentStatus::Completed && $enrichmentResult !== null) {
                EnrichmentResultReadyEvent::dispatch(
                    $enrichmentResult->id,
                    $product->company_id,
                    $product->id,
                    $product->name,
                    $enrichmentResult->enrichment_quality ?? 'unknown',
                    $enrichmentResult->assigned_barcode,
                );
            }
        }

        Log::info('Processed enrichment webhook', [
            'tracking_id' => $event->trackingId,
            'product_id' => $product->id,
            'status' => $enrichmentStatus->value,
        ]);
    }
}
