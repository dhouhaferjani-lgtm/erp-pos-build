<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Jobs;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\Enums\BrandMappingPushResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class SendBrandMappingJob implements ShouldQueue
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
        public readonly string $canonicalBrandId,
        public readonly string $externalBrandId,
        public readonly string $companyId,
    ) {
        $this->onQueue('enrichment');
    }

    public function handle(CompanyContext $companyContext, PlatformSubmissionInterface $submissionService): void
    {
        $companyContext->setCompanyId($this->companyId);

        try {
            $result = $submissionService->pushBrandMapping($this->canonicalBrandId, $this->externalBrandId);

            if ($result === BrandMappingPushResult::Failed
                && config('services.platform.push_enabled', true) === false) {
                return;
            }

            match ($result) {
                BrandMappingPushResult::Mapped => null,
                BrandMappingPushResult::Conflict,
                BrandMappingPushResult::NotFound => Log::error('Brand mapping push terminally rejected', [
                    'canonical_brand_id' => $this->canonicalBrandId,
                    'external_brand_id' => $this->externalBrandId,
                    'company_id' => $this->companyId,
                    'result' => $result->value,
                ]),
                BrandMappingPushResult::Failed => throw new RuntimeException(
                    "Brand mapping push failed for canonical brand [{$this->canonicalBrandId}]",
                ),
            };
        } finally {
            $companyContext->clear();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Brand mapping push permanently failed', [
            'canonical_brand_id' => $this->canonicalBrandId,
            'external_brand_id' => $this->externalBrandId,
            'company_id' => $this->companyId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
