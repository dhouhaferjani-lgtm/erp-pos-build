<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Jobs;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\Enums\EnrichmentFeedbackAction;
use App\Shared\Enums\EnrichmentFeedbackReason;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class SendEnrichmentFeedbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $trackingId,
        public readonly string $action,
        public readonly ?string $reason,
        public readonly ?string $notes,
        public readonly string $companyId,
    ) {
        $this->onQueue('enrichment');
    }

    public function handle(CompanyContext $companyContext, PlatformSubmissionInterface $submissionService): void
    {
        $companyContext->setCompanyId($this->companyId);

        try {
            $sent = $submissionService->sendFeedback(
                $this->trackingId,
                EnrichmentFeedbackAction::from($this->action),
                $this->reason === null ? null : EnrichmentFeedbackReason::from($this->reason),
                $this->notes,
            );

            if (! $sent) {
                throw new RuntimeException("enrichment feedback delivery failed for {$this->trackingId}");
            }
        } finally {
            $companyContext->clear();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Enrichment feedback job failed permanently', [
            'tracking_id' => $this->trackingId,
            'action' => $this->action,
            'reason' => $this->reason,
            'company_id' => $this->companyId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
