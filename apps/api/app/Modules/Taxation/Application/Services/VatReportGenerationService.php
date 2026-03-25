<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Application\DTOs\VatSummaryData;
use App\Modules\Taxation\Domain\Contracts\VatReportStrategyInterface;
use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use App\Modules\Taxation\Domain\Services\VatCreditService;
use App\Modules\Taxation\Infrastructure\Strategies\FranceVatStrategy;
use App\Modules\Taxation\Infrastructure\Strategies\TunisiaVatStrategy;
use App\Modules\Taxation\Infrastructure\Strategies\UkVatStrategy;
use Illuminate\Support\Carbon;

/**
 * VAT Report Generation Service
 *
 * Application service for generating VAT summaries and reports.
 * Resolves the correct country strategy and aggregates VAT data.
 */
class VatReportGenerationService
{
    public function __construct(
        private readonly VatDataRepositoryInterface $vatDataRepository,
        private readonly VatCreditService $creditService,
        private readonly TunisiaVatStrategy $tunisiaStrategy,
        private readonly FranceVatStrategy $franceStrategy,
        private readonly UkVatStrategy $ukStrategy,
    ) {}

    /**
     * Resolve the VAT report strategy for a given country code.
     *
     * @throws \DomainException When the country code is not supported
     */
    public function resolveStrategy(string $countryCode): VatReportStrategyInterface
    {
        return match (strtoupper($countryCode)) {
            'TN' => $this->tunisiaStrategy,
            'FR' => $this->franceStrategy,
            'GB' => $this->ukStrategy,
            default => throw new \DomainException("Unsupported country code: {$countryCode}"),
        };
    }

    /**
     * Generate a VAT summary for a company and date range.
     *
     * Aggregates document tax data, applies the country strategy for declaration mapping,
     * and calculates VAT credit/payable amounts.
     *
     * @param  numeric-string  $creditBroughtForward
     */
    public function generateSummary(
        string $companyId,
        string $countryCode,
        string $dateFrom,
        string $dateTo,
        string $creditBroughtForward = '0.000',
    ): VatSummaryData {
        $strategy = $this->resolveStrategy($countryCode);

        // Aggregate raw VAT data from document tax details
        $aggregations = $this->vatDataRepository->aggregateByRateAndDirection($companyId, $dateFrom, $dateTo);

        // Split into output and input
        $outputBreakdowns = array_filter(
            $aggregations,
            static fn (VatAggregation $a): bool => $a->direction === 'OUTPUT'
        );
        $inputBreakdowns = array_filter(
            $aggregations,
            static fn (VatAggregation $a): bool => $a->direction === 'INPUT'
        );

        // Re-index arrays
        $outputBreakdowns = array_values($outputBreakdowns);
        $inputBreakdowns = array_values($inputBreakdowns);

        // Calculate totals
        $totalOutputVat = '0.000';
        foreach ($outputBreakdowns as $breakdown) {
            $totalOutputVat = bcadd($totalOutputVat, $breakdown->vatAmount, 3);
        }

        $totalInputVat = '0.000';
        foreach ($inputBreakdowns as $breakdown) {
            if ($breakdown->isRecoverable) {
                $totalInputVat = bcadd($totalInputVat, $breakdown->vatAmount, 3);
            }
        }

        // Build domain summary
        $vatSummary = new VatSummary(
            outputBreakdowns: $outputBreakdowns,
            inputBreakdowns: $inputBreakdowns,
            totalOutputVat: $totalOutputVat,
            totalInputVat: $totalInputVat,
        );

        // Map to country-specific declaration
        $declaration = $strategy->mapToDeclaration($vatSummary);

        // Get special line items
        $specialItems = $strategy->getSpecialLineItems(
            $companyId,
            Carbon::parse($dateFrom),
            Carbon::parse($dateTo),
        );

        // Calculate credits
        $creditResult = $this->creditService->calculate(
            $totalOutputVat,
            $totalInputVat,
            $creditBroughtForward,
        );

        return VatSummaryData::fromDomainObjects(
            vatSummary: $vatSummary,
            declaration: $declaration,
            specialItems: $specialItems,
            creditBroughtForward: $creditBroughtForward,
            creditCarriedForward: $creditResult['credit_carried_forward'],
            netVat: $creditResult['net_vat'],
            amountPayable: $creditResult['amount_payable'],
        );
    }
}
