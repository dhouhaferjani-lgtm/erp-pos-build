<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Commands;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Shared\Contracts\EnrichmentQueryInterface;
use App\Shared\DTOs\PendingEnrichmentDTO;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * @cross-tenant-by-design The OUTBOX is fleet-wide (one console command
 * polls all pending enrichments across all tenants); the per-call
 * binding is per-record. Each foreach iteration rebinds CompanyContext
 * to the originating tenant + company of the record being polled, so
 * the outbound HTTP request fired by ProductSubmissionService -->
 * PlatformHttpClient carries X-Tenant-Id + X-Company-Id headers
 * reflecting the per-record originator (api.platform-integration
 * cluster, manual:check-pending-enrichments-per-iteration-rebind).
 */
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
        private readonly EnrichmentQueryInterface $enrichmentQuery,
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pendingProducts = $this->enrichmentQuery->findPendingEnrichments(limit: 50, staleMinutes: 10);

        if ($pendingProducts->isEmpty()) {
            $this->info('No pending enrichments to check.');

            return self::SUCCESS;
        }

        $this->info("Checking {$pendingProducts->count()} pending enrichment(s)...");

        $updatedCount = 0;

        /** @var PendingEnrichmentDTO $dto */
        foreach ($pendingProducts as $dto) {
            // Per-record tenant rebind so the outbound HTTP carries
            // X-Tenant-Id + X-Company-Id headers reflecting THIS record's
            // originating tenant. Without this, the fleet-wide poller
            // would either fail loud (mandatory context throws) or send
            // stale headers from a prior iteration.
            $this->companyContext->setCompanyId($dto->companyId);

            $response = $this->submissionService->checkStatusRaw($dto->platformSubmissionId);

            if ($response === null) {
                Log::warning('Failed to check enrichment status', [
                    'product_id' => $dto->productId,
                    'tracking_id' => $dto->platformSubmissionId,
                ]);

                continue;
            }

            $platformStatus = $response['status'] ?? null;

            if ($platformStatus === null) {
                continue;
            }

            $newStatus = EnrichmentStatus::fromPlatformStatus($platformStatus);

            if ($newStatus === $dto->enrichmentStatus) {
                continue;
            }

            // Status changed — dispatch event for the listener to handle
            EnrichmentWebhookReceived::dispatch(
                $dto->platformSubmissionId,
                $platformStatus,
                $response['enrichment_quality'] ?? null,
                isset($response['assigned_barcode']),
                $response['vertical'] ?? 'unknown',
            );

            $updatedCount++;

            Log::info('Enrichment status changed via polling', [
                'product_id' => $dto->productId,
                'tracking_id' => $dto->platformSubmissionId,
                'old_status' => $dto->enrichmentStatus->value,
                'new_status' => $newStatus->value,
            ]);
        }

        $this->info("Updated {$updatedCount} enrichment(s).");

        return self::SUCCESS;
    }
}
