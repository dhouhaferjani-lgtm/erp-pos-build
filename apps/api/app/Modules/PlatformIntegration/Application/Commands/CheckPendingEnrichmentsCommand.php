<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\EnrichmentQueryInterface;
use App\Shared\DTOs\PendingEnrichmentDTO;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Support\Facades\Log;

/**
 * Poll the platform for status updates on pending enrichment submissions.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter). The pending-submission outbox is
 * `products` — a TENANT table — read through
 * `ProductEnrichmentQueryService::findPendingEnrichments()`, a bare
 * `Product::query()`. This command ran that query ONCE on the scheduler's
 * connection, which under database-per-tenant (flipped 2026-05-28) is CENTRAL,
 * so every 15-minute tick raised 42P01.
 *
 * The per-record `CompanyContext::setCompanyId()` in the loop was NEVER the
 * database selector post-flip — it stamps the outbound `X-Tenant-Id` /
 * `X-Company-Id` headers that PlatformHttpClient sends, and that job is still
 * its own, so the call is deliberately KEPT. It simply is no longer sufficient.
 *
 * **This poller is the enrichment webhook's safety net.** A webhook payload
 * that carries no tenant anchor is discarded by ProcessEnrichmentWebhookJob;
 * this command re-resolves the same submission from the tenant's own database
 * within 15 minutes. Whatever breaks the poller therefore also removes the
 * webhook's fallback — which is why the 42P01 mattered twice over.
 *
 * **`limit: 50` is now a PER-TENANT budget.** It was fleet-wide before, which
 * under database-per-tenant is unimplementable anyway (there is no fleet-wide
 * `products` to take 50 rows from). Per tenant is also the semantics an
 * operator expects from a poller: one noisy tenant can no longer starve the
 * rest of the fleet out of its polling slot.
 */
final class CheckPendingEnrichmentsCommand extends TenantScopedCommand
{
    /**
     * @var string
     */
    protected $signature = 'enrichment:check-pending';

    /**
     * @var string
     */
    protected $description = 'Poll the platform for status updates on pending enrichment submissions, per tenant';

    public function __construct(
        CompanyContext $companyContext,
        private readonly ProductSubmissionService $submissionService,
        private readonly EnrichmentQueryInterface $enrichmentQuery,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $checked = 0;
        $updatedCount = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (&$checked, &$updatedCount): int {
            $pendingProducts = $this->enrichmentQuery->findPendingEnrichments(limit: 50, staleMinutes: 10);

            if ($pendingProducts->isEmpty()) {
                return self::SUCCESS;
            }

            $checked += $pendingProducts->count();

            /** @var PendingEnrichmentDTO $dto */
            foreach ($pendingProducts as $dto) {
                // Per-record CompanyContext rebind so the outbound HTTP carries
                // X-Tenant-Id + X-Company-Id headers reflecting THIS record's
                // originating company. Under database-per-tenant the DATABASE is
                // already selected by the enclosing forEachTenant() binding —
                // this call is about the outbound headers only.
                $this->companyContext->setCompanyId($dto->companyId);

                $response = $this->submissionService->checkStatusRaw($dto->platformSubmissionId);

                if ($response === null) {
                    Log::warning('Failed to check enrichment status', [
                        'tenant_id' => $tenant->id,
                        'product_id' => $dto->productId,
                        'tracking_id' => $dto->platformSubmissionId,
                    ]);

                    continue;
                }

                $platformStatus = $response['status'] ?? null;

                if (! is_string($platformStatus) || $platformStatus === '') {
                    continue;
                }

                $newStatus = EnrichmentStatus::fromPlatformStatus($platformStatus);

                if ($newStatus === $dto->enrichmentStatus) {
                    continue;
                }

                // Status changed — dispatch the event for the listener to
                // handle. The listener resolves the Product from the tracking
                // id, so it MUST run inside this tenant's context: that is what
                // the enclosing forEachTenant() binding provides.
                EnrichmentWebhookReceived::dispatch(
                    $dto->platformSubmissionId,
                    $platformStatus,
                    is_string($response['enrichment_quality'] ?? null) ? $response['enrichment_quality'] : null,
                    isset($response['assigned_barcode']),
                    is_string($response['vertical'] ?? null) ? $response['vertical'] : 'unknown',
                );

                $updatedCount++;

                Log::info('Enrichment status changed via polling', [
                    'tenant_id' => $tenant->id,
                    'product_id' => $dto->productId,
                    'tracking_id' => $dto->platformSubmissionId,
                    'old_status' => $dto->enrichmentStatus->value,
                    'new_status' => $newStatus->value,
                ]);
            }

            return self::SUCCESS;
        });

        if ($checked === 0) {
            $this->info('No pending enrichments to check.');
        } else {
            $this->info("Checked {$checked} pending enrichment(s).");
        }

        $this->info("Updated {$updatedCount} enrichment(s).");

        return $exit;
    }
}
