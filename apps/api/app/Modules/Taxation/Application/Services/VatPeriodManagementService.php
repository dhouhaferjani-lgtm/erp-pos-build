<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Application\DTOs\VatPeriodData;
use App\Modules\Taxation\Application\DTOs\VatSummaryData;
use App\Modules\Taxation\Domain\Contracts\VatReportStrategyInterface;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatDirection;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Events\VatPeriodClosed;
use App\Modules\Taxation\Domain\Events\VatPeriodFiled;
use App\Modules\Taxation\Domain\Repositories\VatPeriodRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * VAT Period Management Service
 *
 * Application service for orchestrating VAT period lifecycle operations:
 * generating periods, closing, reopening, and filing.
 */
class VatPeriodManagementService
{
    public function __construct(
        private readonly VatPeriodRepositoryInterface $periodRepository,
        private readonly VatReportGenerationService $reportService,
    ) {}

    /**
     * Generate VAT periods for a company's fiscal year.
     *
     * Creates periods in the database based on the country strategy.
     *
     * @return VatPeriodData[]
     */
    public function generatePeriods(
        string $companyId,
        string $countryCode,
        int $year,
        VatReportStrategyInterface $strategy,
        int $fiscalYearStartMonth = 1,
    ): array {
        return DB::transaction(function () use ($companyId, $countryCode, $year, $strategy, $fiscalYearStartMonth): array {
            $periodDefinitions = $strategy->generatePeriods($fiscalYearStartMonth, $year);

            $results = [];
            foreach ($periodDefinitions as $definition) {
                $period = $this->periodRepository->create([
                    'company_id' => $companyId,
                    'country_code' => $countryCode,
                    'period_type' => $definition['period_type'],
                    'label' => $definition['label'],
                    'period_start' => $definition['period_start'],
                    'period_end' => $definition['period_end'],
                    'status' => VatPeriodStatus::Open,
                    'credit_brought_forward' => '0.000',
                    'credit_carried_forward' => '0.000',
                    'amount_payable' => '0.000',
                ]);

                $results[] = VatPeriodData::fromEntity($period);
            }

            return $results;
        });
    }

    /**
     * Close a VAT period.
     *
     * Validates the period is open, generates summary from report service,
     * calculates credits, persists breakdowns, and dispatches VatPeriodClosed.
     *
     * @throws \DomainException When period is not in OPEN status
     */
    public function closePeriod(VatPeriod $period, ?string $notes, string $userId): VatPeriodData
    {
        if (! $period->isOpen()) {
            throw new \DomainException('Only open periods can be closed');
        }

        return DB::transaction(function () use ($period, $notes, $userId): VatPeriodData {
            // Get credit brought forward from previous period
            $previousPeriod = $this->periodRepository->findPreviousPeriod($period);
            $creditBroughtForward = $previousPeriod !== null ? $previousPeriod->credit_carried_forward : '0.000';

            // Generate summary using report service
            $summary = $this->reportService->generateSummary(
                $period->company_id,
                $period->country_code,
                $period->period_start->toDateString(),
                $period->period_end->toDateString(),
                $creditBroughtForward,
            );

            // Persist breakdowns
            $this->persistBreakdowns($period, $summary);

            // Update period with calculated totals
            $updatedPeriod = $this->periodRepository->update($period->id, [
                'status' => VatPeriodStatus::Closed,
                'total_output_vat' => $summary->getTotalOutputVat(),
                'total_input_vat' => $summary->getTotalInputVat(),
                'net_vat' => $summary->netVat,
                'credit_brought_forward' => $summary->creditBroughtForward,
                'credit_carried_forward' => $summary->creditCarriedForward,
                'amount_payable' => $summary->amountPayable,
                'special_items' => $summary->specialItems,
                'declaration_data' => $summary->declaration,
                'notes' => $notes,
                'closed_at' => now(),
                'closed_by' => $userId,
            ]);

            DB::afterCommit(function () use ($updatedPeriod): void {
                event(new VatPeriodClosed($updatedPeriod));
            });

            return VatPeriodData::fromEntity($updatedPeriod);
        });
    }

    /**
     * Reopen a closed VAT period.
     *
     * Validates the period is closed (not filed) and no successor periods
     * are closed or filed. Deletes breakdowns and clears totals.
     *
     * @throws \DomainException When period cannot be reopened
     */
    public function reopenPeriod(VatPeriod $period): VatPeriodData
    {
        if (! $period->isClosed()) {
            throw new \DomainException('Only closed periods can be reopened');
        }

        if ($this->periodRepository->hasClosedOrFiledSuccessor($period)) {
            throw new \DomainException('Cannot reopen: a successor period is already closed or filed');
        }

        return DB::transaction(function () use ($period): VatPeriodData {
            // Delete existing breakdowns
            $this->periodRepository->deleteBreakdowns($period->id);

            // Clear totals and reset status
            $updatedPeriod = $this->periodRepository->update($period->id, [
                'status' => VatPeriodStatus::Open,
                'total_output_vat' => null,
                'total_input_vat' => null,
                'net_vat' => null,
                'credit_brought_forward' => '0.000',
                'credit_carried_forward' => '0.000',
                'amount_payable' => '0.000',
                'special_items' => null,
                'declaration_data' => null,
                'closed_at' => null,
                'closed_by' => null,
            ]);

            return VatPeriodData::fromEntity($updatedPeriod);
        });
    }

    /**
     * File a VAT period with the tax authority.
     *
     * Validates the period is closed, sets filed status with reference.
     *
     * @throws \DomainException When period is not in CLOSED status
     */
    public function filePeriod(VatPeriod $period, ?string $filingReference, string $userId): VatPeriodData
    {
        if (! $period->isClosed()) {
            throw new \DomainException('Only closed periods can be filed');
        }

        return DB::transaction(function () use ($period, $filingReference, $userId): VatPeriodData {
            $updatedPeriod = $this->periodRepository->update($period->id, [
                'status' => VatPeriodStatus::Filed,
                'filing_reference' => $filingReference,
                'filed_at' => now(),
                'filed_by' => $userId,
            ]);

            DB::afterCommit(function () use ($updatedPeriod): void {
                event(new VatPeriodFiled($updatedPeriod));
            });

            return VatPeriodData::fromEntity($updatedPeriod);
        });
    }

    /**
     * Persist VAT period breakdowns from summary data.
     */
    private function persistBreakdowns(VatPeriod $period, VatSummaryData $summary): void
    {
        // Persist output breakdowns
        foreach ($summary->outputVat['breakdowns'] as $breakdown) {
            $this->periodRepository->createBreakdown([
                'vat_period_id' => $period->id,
                'direction' => VatDirection::Output,
                'tax_rate' => $breakdown['tax_rate'],
                'tax_configuration_id' => $breakdown['tax_configuration_id'] ?? null,
                'base_amount' => $breakdown['base_amount'],
                'vat_amount' => $breakdown['vat_amount'],
                'document_count' => $breakdown['document_count'],
                'is_recoverable' => $breakdown['is_recoverable'] ?? false,
            ]);
        }

        // Persist input breakdowns
        foreach ($summary->inputVat['breakdowns'] as $breakdown) {
            $this->periodRepository->createBreakdown([
                'vat_period_id' => $period->id,
                'direction' => VatDirection::Input,
                'tax_rate' => $breakdown['tax_rate'],
                'tax_configuration_id' => $breakdown['tax_configuration_id'] ?? null,
                'base_amount' => $breakdown['base_amount'],
                'vat_amount' => $breakdown['vat_amount'],
                'document_count' => $breakdown['document_count'],
                'is_recoverable' => $breakdown['is_recoverable'] ?? true,
            ]);
        }
    }
}
