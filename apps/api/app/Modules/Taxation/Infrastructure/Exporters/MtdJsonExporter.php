<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Exporters;

use App\Modules\Taxation\Domain\Contracts\VatExporterInterface;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MtdJsonExporter implements VatExporterInterface
{
    public function supports(VatExportFormat $format): bool
    {
        return $format === VatExportFormat::MtdJson;
    }

    public function export(VatSummary $summary, VatDeclarationData $declaration): StreamedResponse
    {
        $nineBox = $this->buildNineBox($summary, $declaration);

        $callback = static function () use ($nineBox): void {
            echo json_encode($nineBox, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type' => $this->getContentType(),
            'Content-Disposition' => 'attachment',
        ]);
    }

    public function getContentType(): string
    {
        return 'application/json';
    }

    public function getFilename(VatPeriod $period): string
    {
        $start = $period->period_start->format('Ymd');
        $end = $period->period_end->format('Ymd');

        return "vat_return_{$period->country_code}_{$start}_{$end}.json";
    }

    /**
     * Build the HMRC MTD 9-box VAT return structure.
     *
     * Boxes 1-5: decimal with 2 decimal places
     * Boxes 6-9: integer (whole pounds, no decimals)
     * Box 5 (netVatDue): always positive (absolute value)
     *
     * @return array<string, float|int>
     */
    private function buildNineBox(VatSummary $summary, VatDeclarationData $declaration): array
    {
        $fields = $declaration->fields;

        // Boxes 1-5: decimal (2dp)
        $box1 = $this->toDecimal2((string) ($fields['box1_vat_due_sales'] ?? $summary->totalOutputVat));
        $box2 = $this->toDecimal2((string) ($fields['box2_vat_due_acquisitions'] ?? '0.00'));
        $box3 = $this->toDecimal2((string) ($fields['box3_total_vat_due'] ?? bcadd((string) $box1, (string) $box2, 2)));
        $box4 = $this->toDecimal2((string) ($fields['box4_vat_reclaimed'] ?? $summary->totalInputVat));

        $netVat = bcsub((string) $box3, (string) $box4, 2);
        $box5 = $this->toDecimal2((string) abs((float) $netVat));

        // Boxes 6-9: whole pounds (integers)
        $box6 = $this->toWholePounds((string) ($fields['box6_total_sales_ex_vat'] ?? $this->sumOutputBases($summary)));
        $box7 = $this->toWholePounds((string) ($fields['box7_total_purchases_ex_vat'] ?? $this->sumInputBases($summary)));
        $box8 = $this->toWholePounds((string) ($fields['box8_goods_supplied_ex_vat'] ?? '0'));
        $box9 = $this->toWholePounds((string) ($fields['box9_acquisitions_ex_vat'] ?? '0'));

        return [
            'vatDueSales' => $box1,
            'vatDueAcquisitions' => $box2,
            'totalVatDue' => $box3,
            'vatReclaimedCurrPeriod' => $box4,
            'netVatDue' => $box5,
            'totalValueSalesExVAT' => $box6,
            'totalValuePurchasesExVAT' => $box7,
            'totalValueGoodsSuppliedExVAT' => $box8,
            'totalAcquisitionsExVAT' => $box9,
        ];
    }

    private function toDecimal2(string $value): float
    {
        /** @var numeric-string $value */
        return (float) bcadd($value, '0', 2);
    }

    private function toWholePounds(string $value): int
    {
        return (int) floor((float) $value);
    }

    private function sumOutputBases(VatSummary $summary): string
    {
        $total = '0.000';
        foreach ($summary->outputBreakdowns as $breakdown) {
            /** @var numeric-string $baseAmount */
            $baseAmount = $breakdown->baseAmount;
            $total = bcadd($total, $baseAmount, 3);
        }

        return $total;
    }

    private function sumInputBases(VatSummary $summary): string
    {
        $total = '0.000';
        foreach ($summary->inputBreakdowns as $breakdown) {
            /** @var numeric-string $baseAmount */
            $baseAmount = $breakdown->baseAmount;
            $total = bcadd($total, $baseAmount, 3);
        }

        return $total;
    }
}
