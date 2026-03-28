<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Commands;

use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\Product\Domain\Enums\EnrichmentStatus;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Product\Domain\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class CheckPendingEnrichmentsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'enrichment:check-pending';

    /**
     * @var string
     */
    protected $description = 'Poll the platform for status updates on pending enrichment submissions';

    public function __construct(
        private readonly ProductSubmissionService $submissionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $products = Product::query()
            ->whereIn('enrichment_status', [EnrichmentStatus::Pending, EnrichmentStatus::Enriching])
            ->whereNotNull('platform_submission_id')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->limit(50)
            ->get();

        if ($products->isEmpty()) {
            $this->info('No pending enrichments to check.');

            return self::SUCCESS;
        }

        $this->info("Checking {$products->count()} pending enrichment(s)...");

        $updatedCount = 0;

        foreach ($products as $product) {
            /** @var string $trackingId */
            $trackingId = $product->platform_submission_id;

            $response = $this->submissionService->checkStatus($trackingId);

            if ($response === null) {
                Log::warning('Failed to check enrichment status', [
                    'product_id' => $product->id,
                    'tracking_id' => $trackingId,
                ]);

                continue;
            }

            $platformStatus = $response['status'] ?? null;

            if ($platformStatus === null) {
                continue;
            }

            $newStatus = EnrichmentStatus::fromPlatformStatus($platformStatus);

            if ($newStatus === $product->enrichment_status) {
                continue;
            }

            // Status changed — dispatch event for the listener to handle
            EnrichmentWebhookReceived::dispatch(
                $trackingId,
                $platformStatus,
                $response['enrichment_quality'] ?? null,
                isset($response['assigned_barcode']),
                $response['vertical'] ?? 'unknown',
            );

            $updatedCount++;

            Log::info('Enrichment status changed via polling', [
                'product_id' => $product->id,
                'tracking_id' => $trackingId,
                'old_status' => $product->enrichment_status->value,
                'new_status' => $newStatus->value,
            ]);
        }

        $this->info("Updated {$updatedCount} enrichment(s).");

        return self::SUCCESS;
    }
}
