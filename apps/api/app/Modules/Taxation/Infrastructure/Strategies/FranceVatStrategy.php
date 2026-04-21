<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Strategies;

use App\Modules\Taxation\Domain\Contracts\VatReportStrategyInterface;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use Illuminate\Support\Carbon;

class FranceVatStrategy implements VatReportStrategyInterface
{
    /** @var array<int, string> French month names */
    private const FRENCH_MONTHS = [
        1 => 'Janvier',
        2 => 'Février',
        3 => 'Mars',
        4 => 'Avril',
        5 => 'Mai',
        6 => 'Juin',
        7 => 'Juillet',
        8 => 'Août',
        9 => 'Septembre',
        10 => 'Octobre',
        11 => 'Novembre',
        12 => 'Décembre',
    ];

    public function getDefaultPeriodType(): VatPeriodType
    {
        return VatPeriodType::Monthly;
    }

    /** @return array<int, array{label: string, period_start: string, period_end: string, period_type: string}> */
    public function generatePeriods(int $fiscalYearStartMonth, int $year): array
    {
        $periods = [];

        for ($i = 0; $i < 12; $i++) {
            $month = (($fiscalYearStartMonth - 1 + $i) % 12) + 1;
            $periodYear = $year + intdiv($fiscalYearStartMonth - 1 + $i, 12);
            if ($fiscalYearStartMonth === 1) {
                $periodYear = $year;
            }

            $start = Carbon::create($periodYear, $month, 1);
            /** @var Carbon $start */
            $end = $start->copy()->endOfMonth();

            $label = self::FRENCH_MONTHS[$month].' '.$periodYear;

            $periods[] = [
                'label' => $label,
                'period_start' => $start->format('Y-m-d'),
                'period_end' => $end->format('Y-m-d'),
                'period_type' => VatPeriodType::Monthly->value,
            ];
        }

        return $periods;
    }

    public function mapToDeclaration(VatSummary $summary): VatDeclarationData
    {
        /** @var array<string, string|int|float> $fields */
        $fields = [];

        // CA3 line mapping: rate -> line number
        // Line 08 = 20%, Line 09 = 5.5%, Line 9B = 10%, Line 11 = 2.1%
        $rateLineMap = [
            '20.00' => '08',
            '5.50' => '09',
            '10.00' => '9B',
            '2.10' => '11',
        ];

        foreach ($summary->outputBreakdowns as $breakdown) {
            $line = $rateLineMap[$breakdown->taxRate] ?? $breakdown->taxRate;
            $fields["line_{$line}_base"] = $breakdown->baseAmount;
            $fields["line_{$line}_vat"] = $breakdown->vatAmount;
        }

        $fields['line_16_total_output'] = $summary->totalOutputVat;

        // Lines 19-21: deductible input VAT
        $totalDeductibleGoods = '0.000';
        foreach ($summary->inputBreakdowns as $breakdown) {
            if ($breakdown->isRecoverable) {
                /** @var numeric-string $vatAmount */
                $vatAmount = $breakdown->vatAmount;
                $totalDeductibleGoods = bcadd($totalDeductibleGoods, $vatAmount, 3);
            }
        }
        $fields['line_19_deductible_goods'] = $totalDeductibleGoods;

        return new VatDeclarationData(
            fields: $fields,
            formReference: 'CA3',
        );
    }

    /** @return VatExportFormat[] */
    public function getSupportedExportFormats(): array
    {
        return [VatExportFormat::Pdf, VatExportFormat::Csv, VatExportFormat::Fec];
    }

    /** @return array<string, string|int|float> */
    public function getSpecialLineItems(string $companyId, Carbon $from, Carbon $to): array
    {
        // Intra-community acquisitions/supplies tracking deferred — needs dedicated schema design
        return [
            'intra_community_acquisitions' => '0.000',
            'intra_community_supplies' => '0.000',
        ];
    }

    /** @return string[] */
    public function getExpectedRates(): array
    {
        return ['20.00', '10.00', '5.50', '2.10'];
    }
}
