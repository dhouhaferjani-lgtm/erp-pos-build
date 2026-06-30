<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Exporters;

use App\Modules\Taxation\Domain\Contracts\VatExporterInterface;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Shared\Domain\CurrencyScale;
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
     * All intermediate arithmetic stays in bcmath (numeric-strings).  The ONLY
     * float conversions are at the final return array, required because HMRC MTD
     * VAT JSON must carry numeric (not string) JSON types.
     *
     * @return array<string, float|int>
     */
    private function buildNineBox(VatSummary $summary, VatDeclarationData $declaration): array
    {
        $fields = $declaration->fields;

        // Boxes 1-5: decimal (2dp) — kept as numeric-strings throughout.
        $box1 = $this->toDecimal2((string) ($fields['box1_vat_due_sales'] ?? $summary->totalOutputVat));
        $box2 = $this->toDecimal2((string) ($fields['box2_vat_due_acquisitions'] ?? '0.00'));
        $box3 = $this->toDecimal2((string) ($fields['box3_total_vat_due'] ?? bcadd($box1, $box2, 2)));
        $box4 = $this->toDecimal2((string) ($fields['box4_vat_reclaimed'] ?? $summary->totalInputVat));

        // netVat = box3 − box4 (can be negative when reclaimed > due).
        // Box 5 is always the absolute value — compute via bcmath, no float abs().
        $netVat = bcsub($box3, $box4, 2);
        $absVat = bccomp($netVat, '0', 2) < 0 ? ltrim($netVat, '-') : $netVat;
        $box5 = $this->toDecimal2($absVat);

        // Boxes 6-9: whole pounds (integers)
        $box6 = $this->toWholePounds((string) ($fields['box6_total_sales_ex_vat'] ?? $this->sumOutputBases($summary)));
        $box7 = $this->toWholePounds((string) ($fields['box7_total_purchases_ex_vat'] ?? $this->sumInputBases($summary)));
        $box8 = $this->toWholePounds((string) ($fields['box8_goods_supplied_ex_vat'] ?? '0'));
        $box9 = $this->toWholePounds((string) ($fields['box9_acquisitions_ex_vat'] ?? '0'));

        // HMRC MTD JSON requires numeric types — cast each already-2dp-rounded
        // bcmath string to float exactly once here, at the JSON payload boundary.
        return [
            'vatDueSales' => (float) $box1, // HMRC MTD JSON requires numeric type; value already bcmath-rounded to 2dp
            'vatDueAcquisitions' => (float) $box2, // HMRC MTD JSON requires numeric type; value already bcmath-rounded to 2dp
            'totalVatDue' => (float) $box3, // HMRC MTD JSON requires numeric type; value already bcmath-rounded to 2dp
            'vatReclaimedCurrPeriod' => (float) $box4, // HMRC MTD JSON requires numeric type; value already bcmath-rounded to 2dp
            'netVatDue' => (float) $box5, // HMRC MTD JSON requires numeric type; value already bcmath-rounded to 2dp
            'totalValueSalesExVAT' => $box6,
            'totalValuePurchasesExVAT' => $box7,
            'totalValueGoodsSuppliedExVAT' => $box8,
            'totalAcquisitionsExVAT' => $box9,
        ];
    }

    /**
     * Normalise a value to exactly 2 decimal places using bcmath.
     *
     * Returns a numeric-string (never a float) so all intermediate calculations
     * stay in bcmath.  The caller is responsible for the single float cast at the
     * JSON payload boundary.
     *
     * @return numeric-string
     */
    private function toDecimal2(string $value): string
    {
        return CurrencyScale::bcformat($value, 2);
    }

    private function toWholePounds(string $value): int
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("toWholePounds expects a numeric string, got: {$value}");
        }

        // floor() toward −∞ in bcmath: truncate toward zero first, then subtract 1
        // for any negative value whose fractional part was discarded.
        // After is_numeric() guard above, PHPStan narrows $value to numeric-string.
        $truncated = bcadd($value, '0', 0);
        if (bccomp($value, $truncated, 9) < 0) {
            $truncated = bcsub($truncated, '1', 0);
        }

        return (int) $truncated; // whole-pound integer; bcmath-floored, exact integer string
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
