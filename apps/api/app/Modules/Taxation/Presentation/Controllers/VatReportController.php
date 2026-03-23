<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Application\Services\VatExportService;
use App\Modules\Taxation\Application\Services\VatReportGenerationService;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Presentation\Requests\VatReportRequest;
use App\Modules\Taxation\Presentation\Resources\VatSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class VatReportController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly VatReportGenerationService $reportGenerationService,
        private readonly VatExportService $exportService,
    ) {}

    /**
     * Get ad-hoc VAT summary for a date range.
     */
    public function summary(VatReportRequest $request): VatSummaryResource
    {
        $company = $this->companyContext->requireCompany();

        $summaryData = $this->reportGenerationService->generateSummary(
            $company->id,
            $company->country_code,
            $request->validated('date_from'),
            $request->validated('date_to'),
        );

        return new VatSummaryResource($summaryData->toArray());
    }

    /**
     * Get VAT summary for a specific period.
     */
    public function periodSummary(string $periodId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $period = VatPeriod::query()->forCompany($company->id)->findOrFail($periodId);

        $summaryData = $this->reportGenerationService->generateSummary(
            $company->id,
            $company->country_code,
            $period->period_start->toDateString(),
            $period->period_end->toDateString(),
            $period->credit_brought_forward,
        );

        return response()->json([
            'data' => $summaryData->toArray(),
        ]);
    }

    /**
     * Get available export formats for a period's country.
     */
    public function exportFormats(string $periodId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $period = VatPeriod::query()->forCompany($company->id)->findOrFail($periodId);

        $strategy = $this->reportGenerationService->resolveStrategy($period->country_code);
        $supportedFormats = $strategy->getSupportedExportFormats();

        $formats = array_map(
            static fn (VatExportFormat $format): array => [
                'format' => $format->value,
                'label' => $format->label(),
            ],
            $supportedFormats,
        );

        return response()->json([
            'data' => array_values($formats),
        ]);
    }

    /**
     * Export VAT report in a specific format.
     */
    public function export(string $periodId, string $format): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $period = VatPeriod::query()->forCompany($company->id)->findOrFail($periodId);
        $exportFormat = VatExportFormat::tryFrom(strtoupper($format));

        if ($exportFormat === null) {
            return response()->json([
                'message' => "Unsupported export format: {$format}",
            ], 422);
        }

        if (! $this->exportService->supportsFormat($exportFormat)) {
            return response()->json([
                'message' => "Export format not available: {$exportFormat->label()}",
            ], 422);
        }

        // Return export metadata; actual file generation handled by export service
        return response()->json([
            'data' => [
                'format' => $exportFormat->value,
                'filename' => $this->exportService->getFilename($exportFormat, $period),
                'content_type' => $this->exportService->getContentType($exportFormat),
                'period_label' => $period->label,
            ],
        ]);
    }
}
