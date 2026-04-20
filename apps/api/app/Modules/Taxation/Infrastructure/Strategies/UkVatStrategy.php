<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Strategies;

use App\Modules\Taxation\Domain\Contracts\VatReportStrategyInterface;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use Illuminate\Support\Carbon;

class UkVatStrategy implements VatReportStrategyInterface
{
    public function getDefaultPeriodType(): VatPeriodType
    {
        return VatPeriodType::Quarterly;
    }

    /** @return array<int, array{label: string, period_start: string, period_end: string, period_type: string}> */
    public function generatePeriods(int $fiscalYearStartMonth, int $year): array
    {
        $periods = [];
        $crossesYearBoundary = $fiscalYearStartMonth > 1;
        $nextYear = $year + 1;
        $labelSuffix = $crossesYearBoundary
            ? $year.'/'.substr((string) $nextYear, 2)
            : (string) $year;

        for ($q = 0; $q < 4; $q++) {
            $quarterStartMonth = (($fiscalYearStartMonth - 1 + ($q * 3)) % 12) + 1;
            $periodYear = $year;

            // Adjust year if the quarter wraps into the next calendar year
            if ($crossesYearBoundary && $quarterStartMonth < $fiscalYearStartMonth) {
                $periodYear = $nextYear;
            }

            $start = Carbon::create($periodYear, $quarterStartMonth, 1);
            /** @var Carbon $start */
            $end = $start->copy()->addMonths(2)->endOfMonth();

            $quarterNumber = $q + 1;

            $periods[] = [
                'label' => "Q{$quarterNumber} {$labelSuffix}",
                'period_start' => $start->format('Y-m-d'),
                'period_end' => $end->format('Y-m-d'),
                'period_type' => VatPeriodType::Quarterly->value,
            ];
        }

        return $periods;
    }

    public function mapToDeclaration(VatSummary $summary): VatDeclarationData
    {
        /** @var array<string, string|int|float> $fields */
        $fields = [];

        // Box 1: VAT due on sales and other outputs
        $fields['box_1_vat_due_sales'] = $summary->totalOutputVat;

        // Box 2: VAT due on acquisitions from other EC member states (0 for domestic-only)
        $fields['box_2_vat_due_acquisitions'] = '0.000';

        // Box 3: Total VAT due (box 1 + box 2)
        /** @var numeric-string $totalOutputVat */
        $totalOutputVat = $summary->totalOutputVat;
        $fields['box_3_total_vat_due'] = bcadd($totalOutputVat, '0.000', 3);

        // Box 4: VAT reclaimed on purchases and other inputs
        $totalReclaimed = '0.000';
        foreach ($summary->inputBreakdowns as $breakdown) {
            if ($breakdown->isRecoverable) {
                /** @var numeric-string $vatAmount */
                $vatAmount = $breakdown->vatAmount;
                $totalReclaimed = bcadd($totalReclaimed, $vatAmount, 3);
            }
        }
        $fields['box_4_vat_reclaimed'] = $totalReclaimed;

        // Box 5: Net VAT to pay or reclaim — whole pounds (no decimals), always positive
        /** @var numeric-string $totalVatDue */
        $totalVatDue = $fields['box_3_total_vat_due'];
        $netVat = bcsub($totalVatDue, $totalReclaimed, 3);
        $absNetVat = bccomp($netVat, '0', 3) < 0 ? bcmul($netVat, '-1', 3) : $netVat;
        $fields['box_5_net_vat'] = (int) bcmul($absNetVat, '1', 0);

        // Box 6: Total value of sales excluding VAT — whole pounds
        $totalSalesExVat = '0.000';
        foreach ($summary->outputBreakdowns as $breakdown) {
            /** @var numeric-string $baseAmount */
            $baseAmount = $breakdown->baseAmount;
            $totalSalesExVat = bcadd($totalSalesExVat, $baseAmount, 3);
        }
        $fields['box_6_total_sales_ex_vat'] = (int) bcmul($totalSalesExVat, '1', 0);

        // Box 7: Total value of purchases excluding VAT — whole pounds
        $totalPurchasesExVat = '0.000';
        foreach ($summary->inputBreakdowns as $breakdown) {
            /** @var numeric-string $baseAmount */
            $baseAmount = $breakdown->baseAmount;
            $totalPurchasesExVat = bcadd($totalPurchasesExVat, $baseAmount, 3);
        }
        $fields['box_7_total_purchases_ex_vat'] = (int) bcmul($totalPurchasesExVat, '1', 0);

        // Box 8: Total value of supplies of goods to EU — whole pounds
        $fields['box_8_total_supplies_eu'] = 0;

        // Box 9: Total value of acquisitions of goods from EU — whole pounds
        $fields['box_9_total_acquisitions_eu'] = 0;

        return new VatDeclarationData(
            fields: $fields,
            formReference: 'VAT100',
        );
    }

    /** @return VatExportFormat[] */
    public function getSupportedExportFormats(): array
    {
        return [VatExportFormat::Pdf, VatExportFormat::Csv, VatExportFormat::MtdJson];
    }

    /** @return array<string, string|int|float> */
    public function getSpecialLineItems(string $companyId, Carbon $from, Carbon $to): array
    {
        // EU supply/acquisition tracking deferred — needs dedicated schema design
        return [
            'ec_supplies' => 0,
            'ec_acquisitions' => 0,
        ];
    }

    /** @return string[] */
    public function getExpectedRates(): array
    {
        return ['20.00', '5.00', '0.00'];
    }
}
