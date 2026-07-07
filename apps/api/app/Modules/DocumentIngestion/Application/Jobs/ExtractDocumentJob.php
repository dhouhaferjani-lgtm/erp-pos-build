<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Company\Domain\Company;
use App\Modules\DocumentIngestion\Application\DTO\ConfidenceSummaryData;
use App\Modules\DocumentIngestion\Application\DTO\ExtractedFieldData;
use App\Modules\DocumentIngestion\Application\DTO\ExtractedLineData;
use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use App\Modules\DocumentIngestion\Application\DTO\ReconciliationData;
use App\Modules\DocumentIngestion\Application\Services\ExtractionReconciler;
use App\Modules\DocumentIngestion\Application\Services\MatchSuggestionService;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Shared\Contracts\ExtractionClientInterface;
use App\Shared\Contracts\ExtractionFailedException;
use App\Shared\Contracts\ExtractionHints;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

final class ExtractDocumentJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $ingestionId,
    ) {
        $this->onQueue('ingestion');
    }

    public function handle(
        ExtractionClientInterface $client,
        ExtractionReconciler $reconciler,
        MatchSuggestionService $matchSuggestionService,
    ): void {
        $this->withTenantContext(function () use ($client, $reconciler, $matchSuggestionService): void {
            $ingestion = DocumentIngestion::query()
                ->where('tenant_id', $this->tenantId)
                ->where('company_id', $this->companyId)
                ->where('id', $this->ingestionId)
                ->firstOrFail();

            $company = Company::query()
                ->where('tenant_id', $this->tenantId)
                ->where('id', $this->companyId)
                ->firstOrFail();

            $this->markExtracting($ingestion);

            try {
                $asset = MediaAsset::query()
                    ->where('tenant_id', $this->tenantId)
                    ->where('id', $ingestion->media_asset_id)
                    ->firstOrFail();

                $contents = Storage::disk((string) $asset->storage_disk)->get((string) $asset->storage_path);
                if ($contents === null) {
                    throw new ExtractionFailedException('Source file is missing from object storage.', 'SOURCE_FILE_MISSING');
                }

                $response = $client->extract(
                    $contents,
                    (string) $asset->mime_type,
                    $ingestion->kind,
                    new ExtractionHints(languageHint: 'fr'),
                );
                $result = $response->result;

                $reconciliation = $reconciler->reconcile($result, (string) $company->currency);
                $suggestions = $matchSuggestionService->suggest($ingestion, $result);
                $confidenceSummary = $this->confidenceSummary($result, $reconciliation);

                $ingestion->fill([
                    'provider' => $response->provider,
                    'provider_model' => $response->model,
                    'extraction' => $result->toArray(),
                    'confidence_summary' => $confidenceSummary->toArray(),
                    'suggestions' => $suggestions->toArray(),
                    'error' => null,
                ]);
                $ingestion->save();
                $ingestion->transitionTo(IngestionStatus::NeedsReview);
            } catch (ExtractionFailedException $exception) {
                $this->markFailed($ingestion, $exception->failureCode(), $exception->getMessage());

                throw $exception;
            } catch (\Throwable $exception) {
                $this->markFailed($ingestion, 'EXTRACTION_FAILED', $exception->getMessage());

                throw $exception;
            }
        });
    }

    private function markExtracting(DocumentIngestion $ingestion): void
    {
        if (in_array($ingestion->status, [IngestionStatus::Uploaded, IngestionStatus::Failed, IngestionStatus::Extracting], true)) {
            $ingestion->transitionTo(IngestionStatus::Extracting);

            return;
        }

        throw new \DomainException("Ingestion [{$ingestion->id}] is not extractable from status [{$ingestion->status->value}].");
    }

    private function markFailed(DocumentIngestion $ingestion, string $code, string $message): void
    {
        $ingestion->error = [
            'code' => $code,
            'message' => $message,
        ];
        $ingestion->save();

        if ($ingestion->status === IngestionStatus::Extracting) {
            $ingestion->transitionTo(IngestionStatus::Failed);
        }
    }

    private function confidenceSummary(ExtractionResultData $result, ReconciliationData $reconciliation): ConfidenceSummaryData
    {
        $confidences = [];
        $low = [];

        foreach ($result->supplier as $key => $field) {
            $this->collectConfidence("supplier.{$key}", $field, $confidences, $low);
        }

        foreach ($result->header as $key => $field) {
            $this->collectConfidence("header.{$key}", $field, $confidences, $low);
        }

        foreach ($result->lines as $index => $line) {
            foreach ($this->lineFields($line) as $key => $field) {
                if ($field !== null) {
                    $this->collectConfidence("lines.{$index}.{$key}", $field, $confidences, $low);
                }
            }
        }

        return new ConfidenceSummaryData(
            averageConfidence: $confidences === [] ? null : array_sum($confidences) / count($confidences),
            lowConfidenceFields: $low,
            reconciliation: $reconciliation,
        );
    }

    /**
     * @param  list<float>  $confidences
     * @param  list<string>  $low
     */
    private function collectConfidence(string $path, ExtractedFieldData $field, array &$confidences, array &$low): void
    {
        $confidences[] = $field->confidence;
        if ($field->confidence < 0.75) {
            $low[] = $path;
        }
    }

    /**
     * @return array<string, ExtractedFieldData|null>
     */
    private function lineFields(ExtractedLineData $line): array
    {
        return [
            'description' => $line->description,
            'supplier_ref' => $line->supplierRef,
            'quantity' => $line->quantity,
            'unit_price' => $line->unitPrice,
            'tax_rate' => $line->taxRate,
            'line_total' => $line->lineTotal,
            'batch_number' => $line->batchNumber,
            'expiry_date' => $line->expiryDate,
        ];
    }
}
