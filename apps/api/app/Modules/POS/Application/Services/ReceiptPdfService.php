<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Domain\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\Carbon;
use NumberFormatter;

/**
 * Receipt PDF Generation Service
 *
 * Generates thermal-receipt-style PDFs for POS receipts.
 * Optimized for 58mm/80mm thermal printer widths.
 *
 * CRITICAL: Must include fiscal hash and chain sequence for compliance.
 */
final class ReceiptPdfService
{
    /**
     * Generate PDF for a receipt.
     *
     * @param  Receipt  $receipt  The receipt to generate PDF for
     * @param  bool  $stream  Whether to return stream or download response
     */
    public function generate(Receipt $receipt, bool $stream = false): DomPdf
    {
        $receipt->load([
            'company',
            'location',
            'terminal',
            'cashier',
            'lines.product',
            'vatDetails',
            'payments.paymentMethod',
        ]);

        $company = $receipt->company;
        $data = $this->prepareData($receipt, $company);

        $pdf = Pdf::loadView('pos.receipt', $data);

        // Set paper size for thermal receipt (80mm width)
        // 80mm = ~3.15 inches, height auto-adjusts
        $pdf->setPaper([0, 0, 226.77, 841.89], 'portrait'); // 80mm x 297mm

        // Set PDF options
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Sans Mono'); // Monospace for alignment

        return $pdf;
    }

    /**
     * Generate and return PDF as binary string.
     */
    public function generateContent(Receipt $receipt): string
    {
        return $this->generate($receipt)->output();
    }

    /**
     * Get filename for the receipt PDF.
     */
    public function getFilename(Receipt $receipt): string
    {
        $number = str_replace(['/', '\\', ' '], '-', $receipt->receipt_number);

        return "receipt-{$number}.pdf";
    }

    /**
     * Prepare data for the PDF template.
     *
     * @return array<string, mixed>
     */
    private function prepareData(Receipt $receipt, Company $company): array
    {
        $locale = $company->locale ?? 'en';
        $currency = $receipt->currency ?? $company->currency;

        // Calculate change given if any
        $totalPaid = $receipt->payments->sum('amount');
        $changeGiven = (float) $totalPaid > (float) $receipt->total
            ? bcsub((string) $totalPaid, (string) $receipt->total, 2)
            : '0.00';

        return [
            'receipt' => $receipt,
            'company' => $company,
            'location' => $receipt->location,
            'terminal' => $receipt->terminal,
            'cashier' => $receipt->cashier,
            'lines' => $receipt->lines,
            'vatDetails' => $receipt->vatDetails,
            'payments' => $receipt->payments,
            'locale' => $locale,
            'currency' => $currency,
            'changeGiven' => $changeGiven,
            'totalPaid' => $totalPaid,
            'formatMoney' => fn (string|float|null $amount) => $this->formatMoney($amount, $currency, $locale),
            'formatDate' => fn (Carbon|string|null $date) => $this->formatDate($date, $locale),
            'formatDateTime' => fn (Carbon|string|null $date) => $this->formatDateTime($date, $locale),
            'formatNumber' => fn (string|float|null $number, int $decimals = 2) => $this->formatNumber($number, $decimals, $locale),
        ];
    }

    /**
     * Format money amount with currency.
     */
    private function formatMoney(string|float|null $amount, string $currency, string $locale): string
    {
        if ($amount === null) {
            return '0.00';
        }

        $amount = is_string($amount) ? (float) $amount : $amount;

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $result = $formatter->formatCurrency($amount, $currency);

        return $result !== false ? $result : number_format($amount, 2).' '.$currency;
    }

    /**
     * Format date according to locale.
     */
    private function formatDate(Carbon|string|null $date, string $locale): string
    {
        if ($date === null) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->locale($locale)->isoFormat('L');
    }

    /**
     * Format date and time according to locale.
     */
    private function formatDateTime(Carbon|string|null $date, string $locale): string
    {
        if ($date === null) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->locale($locale)->isoFormat('L LT');
    }

    /**
     * Format number with locale-specific formatting.
     */
    private function formatNumber(string|float|null $number, int $decimals, string $locale): string
    {
        if ($number === null) {
            return '0';
        }

        $number = is_string($number) ? (float) $number : $number;

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);

        $result = $formatter->format($number);

        return $result !== false ? $result : number_format($number, $decimals);
    }
}
