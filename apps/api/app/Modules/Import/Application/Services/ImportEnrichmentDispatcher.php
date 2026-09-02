<?php

declare(strict_types=1);

namespace App\Modules\Import\Application\Services;

use App\Modules\Import\Application\Jobs\EnrichImportedProductsJob;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;

final class ImportEnrichmentDispatcher
{
    public function dispatchIfEnabled(ImportJob $job, string $companyId, string $tenantId): bool
    {
        if ($job->type !== ImportType::Products
            || ! $job->status->isTerminal()
            || ! (bool) ($job->options['enrichment_enabled'] ?? false)
            || $job->successful_rows === 0) {
            return false;
        }

        EnrichImportedProductsJob::dispatch($job->id, $tenantId, $companyId);

        return true;
    }
}
