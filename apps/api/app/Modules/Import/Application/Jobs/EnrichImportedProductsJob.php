<?php

declare(strict_types=1);

namespace App\Modules\Import\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Import\Domain\Data\ImportEnrichmentSummaryData;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\Enums\ImportWarningCode;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Product\Application\Services\CatalogBacklinkDispatcher;
use App\Shared\Contracts\CatalogLookupInterface;
use App\Shared\Enums\CatalogLookupOutcome;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class EnrichImportedProductsJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const DISTINCT_LOOKUP_CAP = 500;

    public int $tries = 1;

    // Queue timing contract: 50s job timeout + 10s worker-stop margin = the
    // 60s Horizon timeout, which is still 30s below Redis retry_after=90.
    // The first worker is therefore stopped before Redis can re-reserve the
    // payload; the backlink guard below still makes a rare replay idempotent.
    public int $timeout = 50;

    public function __construct(
        public readonly string $importJobId,
        public readonly string $tenantId,
        public readonly string $companyId,
    ) {
        $this->onQueue('enrichment');
    }

    public function handle(
        CompanyContext $companyContext,
        CatalogLookupInterface $lookup,
        CatalogBacklinkDispatcher $backlinks,
    ): void {
        $this->withTenantContext(function () use ($companyContext, $lookup, $backlinks): void {
            $job = ImportJob::query()
                ->where('id', $this->importJobId)
                ->where('tenant_id', $this->tenantId)
                ->where('company_id', $this->companyId)
                ->where('type', ImportType::Products)
                ->whereIn('status', [ImportStatus::Completed, ImportStatus::Failed])
                ->first();

            if ($job === null || ! (bool) ($job->options['enrichment_enabled'] ?? false)) {
                return;
            }

            $company = Company::query()
                ->where('id', $this->companyId)
                ->where('tenant_id', $this->tenantId)
                ->first();
            if ($company === null) {
                return;
            }

            $summary = [];
            $priorEnriched = $job->enrichment_summary['enriched'] ?? 0;
            if ($priorEnriched > 0) {
                $summary['enriched'] = $priorEnriched;
            }
            $companyContext->setCompanyId($this->companyId);

            try {
                $vertical = $company->tenant->vertical->platformVertical();
                if ($vertical === null) {
                    $summary[ImportWarningCode::EnrichmentVerticalNotSupported->value] = 1;
                    $this->storeSummary($summary);

                    return;
                }

                /** @var array<string, list<array{row: ImportRow, product_id: string, barcode: string}>> $groups */
                $groups = [];
                $rows = $job->rows()
                    ->where('outcome', ImportRowOutcome::Imported)
                    ->whereNotNull('imported_entity_id')
                    ->get();

                foreach ($rows as $row) {
                    $productId = $row->imported_entity_id;
                    if (! is_string($productId)) {
                        continue;
                    }

                    if (! $backlinks->productExists($productId, $this->companyId, $this->tenantId)
                        || $backlinks->hasBacklink($productId, $this->companyId, $this->tenantId)) {
                        continue;
                    }

                    $barcode = $row->data['barcode'] ?? null;
                    if (! is_string($barcode) || trim($barcode) === '') {
                        $this->warn($row, ImportWarningCode::EnrichmentBarcodeMissing, 'barcode missing');
                        $this->increment($summary, ImportWarningCode::EnrichmentBarcodeMissing->value);

                        continue;
                    }

                    $normalized = $lookup->normalizeBarcode($barcode);
                    if ($normalized === null) {
                        $this->warn($row, ImportWarningCode::EnrichmentInvalidBarcode, $barcode);
                        $this->increment($summary, ImportWarningCode::EnrichmentInvalidBarcode->value);

                        continue;
                    }

                    $groups['barcode:'.$normalized][] = ['row' => $row, 'product_id' => $productId, 'barcode' => $barcode];
                }

                $lookupCount = 0;
                foreach ($groups as $groupKey => $group) {
                    if ($lookupCount >= self::DISTINCT_LOOKUP_CAP) {
                        foreach ($group as $candidate) {
                            $this->warn($candidate['row'], ImportWarningCode::EnrichmentCapExceeded, $candidate['barcode']);
                            $this->increment($summary, ImportWarningCode::EnrichmentCapExceeded->value);
                        }

                        continue;
                    }

                    $lookupCount++;
                    $result = $lookup->lookup(substr($groupKey, strlen('barcode:')), $vertical);
                    $outcome = $result->outcome();

                    if ($outcome === CatalogLookupOutcome::VerticalNotSupported) {
                        $summary[ImportWarningCode::EnrichmentVerticalNotSupported->value] = 1;
                        break;
                    }

                    if ($outcome === CatalogLookupOutcome::Found) {
                        $platformProductId = $result->platformProductId();
                        foreach ($group as $candidate) {
                            $linked = is_string($platformProductId)
                                && Str::isUuid($platformProductId)
                                && $backlinks->linkAndApply(
                                    productId: $candidate['product_id'],
                                    companyId: $this->companyId,
                                    tenantId: $this->tenantId,
                                    platformProductId: $platformProductId,
                                    barcode: $candidate['barcode'],
                                    vertical: $vertical,
                                );

                            if ($linked) {
                                $this->increment($summary, 'enriched');
                            } else {
                                $this->warn($candidate['row'], ImportWarningCode::EnrichmentUnavailable, $candidate['barcode']);
                                $this->increment($summary, ImportWarningCode::EnrichmentUnavailable->value);
                            }
                        }

                        continue;
                    }

                    $warningByOutcome = [
                        CatalogLookupOutcome::NotFound->value => ImportWarningCode::EnrichmentNotFound,
                        CatalogLookupOutcome::InvalidBarcode->value => ImportWarningCode::EnrichmentInvalidBarcode,
                        CatalogLookupOutcome::PlatformUnavailable->value => ImportWarningCode::EnrichmentUnavailable,
                        CatalogLookupOutcome::PlatformError->value => ImportWarningCode::EnrichmentUnavailable,
                    ];
                    $warning = $warningByOutcome[$outcome->value];
                    foreach ($group as $candidate) {
                        $this->warn($candidate['row'], $warning, $candidate['barcode']);
                        $this->increment($summary, $warning->value);
                    }

                    if ($outcome === CatalogLookupOutcome::PlatformUnavailable || $lookup->isCircuitOpen()) {
                        $this->markRemainingUnavailable($groups, $groupKey, $summary);
                        Log::warning('Import enrichment stopped after platform circuit opened', [
                            'import_job_id' => $this->importJobId,
                        ]);
                        break;
                    }
                }

                $this->storeSummary($summary);
            } finally {
                $companyContext->clear();
            }
        });
    }

    /** @param array<string, int> $summary */
    private function storeSummary(array $summary): void
    {
        $normalized = ImportEnrichmentSummaryData::fromStorage($summary)->toStorage();
        ImportJob::query()
            ->where('id', $this->importJobId)
            ->where('tenant_id', $this->tenantId)
            ->where('company_id', $this->companyId)
            ->whereIn('status', [ImportStatus::Completed, ImportStatus::Failed])
            ->update(['enrichment_summary' => json_encode($normalized, JSON_THROW_ON_ERROR)]);
    }

    private function warn(ImportRow $row, ImportWarningCode $code, string $barcode): void
    {
        $warnings = $row->warnings ?? [];
        foreach ($warnings as $warning) {
            if (($warning['code'] ?? null) === $code->value) {
                return;
            }
        }
        $warnings[] = ['code' => $code->value, 'detail' => $barcode];
        $row->update(['warnings' => $warnings]);
    }

    /** @param array<string, int> $summary */
    private function increment(array &$summary, string $key): void
    {
        $summary[$key] = ($summary[$key] ?? 0) + 1;
    }

    /**
     * @param  array<string, list<array{row: ImportRow, product_id: string, barcode: string}>>  $groups
     * @param  array<string, int>  $summary
     */
    private function markRemainingUnavailable(array $groups, string $currentBarcode, array &$summary): void
    {
        $afterCurrent = false;
        foreach ($groups as $barcode => $group) {
            if (! $afterCurrent) {
                $afterCurrent = $barcode === $currentBarcode;

                continue;
            }

            foreach ($group as $candidate) {
                $this->warn($candidate['row'], ImportWarningCode::EnrichmentUnavailable, $candidate['barcode']);
                $this->increment($summary, ImportWarningCode::EnrichmentUnavailable->value);
            }
        }
    }
}
