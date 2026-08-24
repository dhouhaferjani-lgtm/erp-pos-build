<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Application\Services\VatExportService;
use App\Modules\Taxation\Application\Services\VatReportGenerationService;
use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatDirection;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Presentation\Requests\VatReportRequest;
use App\Modules\Taxation\Presentation\Resources\VatSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     *
     * For OPEN periods, re-queries the database for live data.
     * For CLOSED/FILED periods, returns the persisted snapshot.
     */
    public function periodSummary(string $periodId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $period = VatPeriod::query()->forCompany($company->id)->findOrFail($periodId);

        if ($period->isOpen()) {
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

        // CLOSED/FILED: return persisted snapshot data
        $period->load('breakdowns');

        $outputBreakdowns = [];
        $inputBreakdowns = [];
        $outputBase = '0.000';
        $inputBase = '0.000';

        foreach ($period->breakdowns as $breakdown) {
            // N-4: this snapshot branch used to emit `rate` and omit
            // `tax_configuration_id`, so a CLOSED/FILED period produced breakdown
            // rows the frontend (typed off the OPEN branch's VatAggregation shape)
            // could not read. The two branches must emit the SAME row keys —
            // VatReportSummaryContractTest pins that.
            $row = [
                'direction' => $breakdown->direction->value,
                'tax_rate' => $breakdown->tax_rate,
                'base_amount' => $breakdown->base_amount,
                'vat_amount' => $breakdown->vat_amount,
                'document_count' => $breakdown->document_count,
                'is_recoverable' => $breakdown->is_recoverable,
                'tax_configuration_id' => $breakdown->tax_configuration_id,
            ];

            if ($breakdown->direction === VatDirection::Output) {
                $outputBreakdowns[] = $row;
                $outputBase = bcadd($outputBase, $breakdown->base_amount, 3);
            } else {
                $inputBreakdowns[] = $row;
                $inputBase = bcadd($inputBase, $breakdown->base_amount, 3);
            }
        }

        return response()->json([
            'data' => [
                'output_vat' => [
                    'total_base' => $outputBase,
                    'total_vat' => $period->total_output_vat ?? '0.000',
                    'breakdowns' => $outputBreakdowns,
                ],
                'input_vat' => [
                    'total_base' => $inputBase,
                    'total_vat' => $period->total_input_vat ?? '0.000',
                    'breakdowns' => $inputBreakdowns,
                ],
                'net_vat' => $period->net_vat ?? '0.000',
                'credit_brought_forward' => $period->credit_brought_forward,
                'credit_carried_forward' => $period->credit_carried_forward,
                'amount_payable' => $period->amount_payable,
                'special_items' => $period->special_items ?? [],
                'declaration' => $period->declaration_data ?? [],
            ],
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
    public function export(string $periodId, string $format): StreamedResponse|JsonResponse
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

        // Generate summary data for the period
        $summaryData = $this->reportGenerationService->generateSummary(
            $company->id,
            $company->country_code,
            $period->period_start->toDateString(),
            $period->period_end->toDateString(),
            $period->credit_brought_forward,
        );

        // Build domain objects for the exporter
        $strategy = $this->reportGenerationService->resolveStrategy($company->country_code);

        $vatSummary = new VatSummary(
            outputBreakdowns: array_map(
                static function (array $b): VatAggregation {
                    /** @var numeric-string $taxRate */
                    $taxRate = (string) $b['tax_rate'];
                    /** @var numeric-string $baseAmount */
                    $baseAmount = (string) $b['base_amount'];
                    /** @var numeric-string $vatAmount */
                    $vatAmount = (string) $b['vat_amount'];

                    return new VatAggregation(
                        direction: (string) ($b['direction'] ?? 'OUTPUT'),
                        taxRate: $taxRate,
                        baseAmount: $baseAmount,
                        vatAmount: $vatAmount,
                        documentCount: (int) $b['document_count'],
                        isRecoverable: (bool) ($b['is_recoverable'] ?? false),
                        taxConfigurationId: isset($b['tax_configuration_id']) ? (string) $b['tax_configuration_id'] : null,
                    );
                },
                $summaryData->outputVat['breakdowns'],
            ),
            inputBreakdowns: array_map(
                static function (array $b): VatAggregation {
                    /** @var numeric-string $taxRate */
                    $taxRate = (string) $b['tax_rate'];
                    /** @var numeric-string $baseAmount */
                    $baseAmount = (string) $b['base_amount'];
                    /** @var numeric-string $vatAmount */
                    $vatAmount = (string) $b['vat_amount'];

                    return new VatAggregation(
                        direction: (string) ($b['direction'] ?? 'INPUT'),
                        taxRate: $taxRate,
                        baseAmount: $baseAmount,
                        vatAmount: $vatAmount,
                        documentCount: (int) $b['document_count'],
                        isRecoverable: (bool) ($b['is_recoverable'] ?? true),
                        taxConfigurationId: isset($b['tax_configuration_id']) ? (string) $b['tax_configuration_id'] : null,
                    );
                },
                $summaryData->inputVat['breakdowns'],
            ),
            totalOutputVat: $summaryData->getTotalOutputVat(),
            totalInputVat: $summaryData->getTotalInputVat(),
        );

        $declaration = $strategy->mapToDeclaration($vatSummary);

        return $this->exportService->export($vatSummary, $declaration, $exportFormat, $period);
    }
}
