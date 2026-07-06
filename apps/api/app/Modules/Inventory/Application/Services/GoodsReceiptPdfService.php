<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Partner\Domain\Partner;
use App\Shared\Domain\CurrencyScale;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\CarbonInterface;
use NumberFormatter;

final class GoodsReceiptPdfService
{
    public function generate(GoodsReceipt $receipt): DomPdf
    {
        $pdf = Pdf::loadView('inventory.goods_receipt', $this->viewDataFor($receipt));
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Sans');

        return $pdf;
    }

    public function getFilename(GoodsReceipt $receipt): string
    {
        $number = str_replace(['/', '\\', ' '], '-', (string) $receipt->receipt_number);

        return "GRN-{$number}.pdf";
    }

    /**
     * @return array<string, mixed>
     */
    public function viewDataFor(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing([
            'company',
            'lines.product',
            'purchaseOrder.partner',
            'purchaseOrder.location',
            'receiver',
        ]);

        /** @var Company $company */
        $company = $receipt->company;
        $purchaseOrder = $receipt->purchaseOrder;
        /** @var Partner|null $supplier */
        $supplier = $purchaseOrder->partner;
        /** @var Location|null $location */
        $location = $purchaseOrder->location;
        $locale = $company->locale ?? 'en';

        return [
            'receipt' => $receipt,
            'lines' => $receipt->lines,
            'supplier' => $supplier,
            'po_number' => $purchaseOrder->document_number,
            'external_reference' => $receipt->external_reference,
            'external_date' => $receipt->external_date,
            'location' => $location,
            'company' => $company,
            'locale' => $locale,
            'formatDate' => fn (CarbonInterface|string|null $date): string => $this->formatDate($date),
            'formatNumber' => fn (string|float|int|null $number, int $decimals = 2): string => $this->formatNumber($number, $decimals, $locale),
        ];
    }

    private function formatDate(CarbonInterface|string|null $date): string
    {
        if ($date === null) {
            return '-';
        }

        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        return $date;
    }

    private function formatNumber(string|float|int|null $number, int $decimals, string $locale): string
    {
        if ($number === null) {
            return '-';
        }

        $formatted = CurrencyScale::bcformatStrict((string) $number, $decimals);
        $formatter = new NumberFormatter(str_replace('_', '-', $locale), NumberFormatter::DECIMAL);
        $decimalSeparator = $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) ?: '.';
        $groupingSeparator = $formatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL) ?: ',';
        $negative = str_starts_with($formatted, '-');
        $unsigned = $negative ? substr($formatted, 1) : $formatted;
        [$integerPart, $fractionPart] = array_pad(explode('.', $unsigned, 2), 2, '');
        $groupedInteger = preg_replace('/\B(?=(\d{3})+(?!\d))/', $groupingSeparator, $integerPart) ?: $integerPart;

        return ($negative ? '-' : '')
            .$groupedInteger
            .($decimals > 0 ? $decimalSeparator.str_pad($fractionPart, $decimals, '0') : '');
    }
}
