<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Listeners;

use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Product\Domain\Product;
use App\Shared\Enums\EnrichmentStatus;
use App\Shared\Events\EnrichmentResultReadyEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

final class ProcessEnrichmentEventListener
{
    public function __construct(
        private readonly EnrichmentReviewService $enrichmentReviewService,
    ) {}

    public function handle(EnrichmentWebhookReceived $event): void
    {
        // ->sole() throws ModelNotFoundException on zero rows AND
        // MultipleRecordsFoundException on >1 row. The DB UNIQUE
        // constraint added by migration
        // 2026_05_08_000001_add_unique_to_products_platform_submission_id
        // makes the >1-row case structurally impossible — but leaving
        // ->first() in place would mask a regression if the constraint
        // were ever dropped (emergency rollback, migration mistake,
        // partner restore, etc.). Defense-in-depth pairing with the
        // migration: code AND schema both reject the collision shape.
        try {
            $product = Product::where('platform_submission_id', $event->trackingId)->sole();
        } catch (ModelNotFoundException) {
            // Legitimate async case: webhook arrived before the local
            // Product was created (or after the Product was deleted).
            // Log + return — do NOT crash the queue worker.
            Log::warning('Enrichment webhook received for unknown tracking ID', [
                'tracking_id' => $event->trackingId,
                'status' => $event->status,
            ]);

            return;
        }
        // Note: MultipleRecordsFoundException intentionally NOT caught.
        // It signals a data-integrity violation (cross-tenant collision
        // on platform_submission_id) and must crash loud so the alert
        // surfaces — silently picking one row would risk dispatching
        // EnrichmentResultReadyEvent to the wrong tenant's company.

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
