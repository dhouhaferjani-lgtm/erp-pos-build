<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Strategies;

use App\Modules\Taxation\Domain\Contracts\VatReportStrategyInterface;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TunisiaVatStrategy implements VatReportStrategyInterface
{
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

            $periods[] = [
                'label' => $start->format('F Y'),
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

        $rateFieldMap = [
            '19.00' => '19',
            '13.00' => '13',
            '7.00' => '7',
            '0.00' => '0',
        ];

        // Map output breakdowns to DGI form fields
        foreach ($summary->outputBreakdowns as $breakdown) {
            $suffix = $rateFieldMap[$breakdown->taxRate] ?? $breakdown->taxRate;
            $fields["base_{$suffix}"] = $breakdown->baseAmount;
            $fields["vat_{$suffix}"] = $breakdown->vatAmount;
        }

        $fields['total_output_vat'] = $summary->totalOutputVat;

        // Calculate total deductible (recoverable input VAT)
        $totalDeductible = '0.000';
        foreach ($summary->inputBreakdowns as $breakdown) {
            if ($breakdown->isRecoverable) {
                /** @var numeric-string $vatAmount */
                $vatAmount = $breakdown->vatAmount;
                $totalDeductible = bcadd($totalDeductible, $vatAmount, 3);
            }
        }
        $fields['total_deductible_vat'] = $totalDeductible;

        return new VatDeclarationData(
            fields: $fields,
            formReference: 'DGI',
        );
    }

    /** @return VatExportFormat[] */
    public function getSupportedExportFormats(): array
    {
        return [VatExportFormat::Pdf, VatExportFormat::Csv, VatExportFormat::TeifXml];
    }

    /** @return array<string, string|int|float> */
    public function getSpecialLineItems(string $companyId, Carbon $from, Carbon $to): array
    {
        // Query stamp duty count from document_tax_details
        $stampDutyCount = (int) DB::table('document_tax_details')
            ->join('documents', 'documents.id', '=', 'document_tax_details.document_id')
            ->where('documents.company_id', $companyId)
            ->where('document_tax_details.is_stamp_duty', true)
            ->whereBetween('documents.document_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $stampDutyTotal = DB::table('document_tax_details')
            ->join('documents', 'documents.id', '=', 'document_tax_details.document_id')
            ->where('documents.company_id', $companyId)
            ->where('document_tax_details.is_stamp_duty', true)
            ->whereBetween('documents.document_date', [$from->toDateString(), $to->toDateString()])
            ->sum('document_tax_details.tax_amount');

        // Query retenue (withholding) from withholding_certificates
        $retenueTotal = DB::table('withholding_certificates')
            ->where('company_id', $companyId)
            ->where('direction', 'sales')
            ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()])
            ->sum('withholding_amount');

        return [
            'stamp_duty_count' => $stampDutyCount,
            'stamp_duty_total' => bcadd((string) $stampDutyTotal, '0', 3),
            'retenue_source_total' => bcadd((string) $retenueTotal, '0', 3),
        ];
    }

    /** @return string[] */
    public function getExpectedRates(): array
    {
        return ['19.00', '13.00', '7.00', '0.00'];
    }
}
