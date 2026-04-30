<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\Carbon;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
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
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ReceiptQrTokenIssuanceService $qrTokenIssuanceService,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Generate PDF for a receipt.
     *
     * @param  Receipt  $receipt  The receipt to generate PDF for
     * @param  bool  $stream  Whether to return stream or download response
     */
    public function generate(Receipt $receipt, bool $stream = false, int $copyNumber = 1): DomPdf
    {
        $relations = [
            'company',
            'location',
            'terminal',
            'cashier',
            'lines.product',
            'vatDetails',
            'payments.paymentMethod',
        ];

        if ($receipt->receipt_type === ReceiptType::Return) {
            $relations[] = 'originalReceipt';
        }

        $receipt->load($relations);

        $company = $receipt->company;
        $data = $this->prepareData($receipt, $company, $copyNumber);

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
    private function prepareData(Receipt $receipt, Company $company, int $copyNumber = 1): array
    {
        $locale = $company->locale ?? 'en';
        $currency = $receipt->currency ?? $company->currency;

        // Calculate change given. Prefer the persisted column (new rows);
        // fall back to totalPaid - total for legacy rows written before the
        // 2026-04-23 migration (BG6).
        $totalPaid = $receipt->payments->sum('amount');
        /** @var numeric-string $totalPaidStr */
        $totalPaidStr = (string) $totalPaid;
        /** @var numeric-string $receiptTotal */
        $receiptTotal = (string) $receipt->total;

        if ($receipt->change_due !== null) {
            $changeGiven = CurrencyScale::bcformat((string) $receipt->change_due, $this->scale());
        } else {
            $changeGiven = (float) $totalPaid > (float) $receipt->total
                ? bcsub($totalPaidStr, $receiptTotal, $this->scale())
                : CurrencyScale::bcformat('0', $this->scale());
        }

        $isReturn = $receipt->receipt_type === ReceiptType::Return;

        // Issue QR token for this receipt (null when no active signing key).
        $qrToken = $this->qrTokenIssuanceService->issueTokenFor($receipt);
        $qrSvg = $qrToken !== null ? $this->renderQrSvg($qrToken) : null;

        // For return receipts, also issue the original receipt's QR token.
        $originalQrToken = null;
        $originalQrSvg = null;
        if ($isReturn && $receipt->originalReceipt !== null) {
            $originalQrToken = $this->qrTokenIssuanceService->issueTokenFor($receipt->originalReceipt);
            $originalQrSvg = $originalQrToken !== null ? $this->renderQrSvg($originalQrToken) : null;
        }

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
            'isReturn' => $isReturn,
            'originalReceiptNumber' => $isReturn ? $receipt->originalReceipt?->receipt_number : null,
            'returnReason' => $isReturn ? $receipt->return_reason?->label() : null,
            'copyNumber' => $copyNumber,
            'isDuplicate' => $copyNumber >= 2,
            'duplicateLabel' => $copyNumber >= 2 ? "DUPLICATA #{$copyNumber}" : null,
            // Receipt customization: section visibility with legal overrides
            'forceVatBreakdown' => in_array($company->country_code, ['FR', 'TN', 'IT', 'MA', 'DZ'], true),
            'forceFiscalInfo' => in_array($company->country_code, ['FR'], true),
            'forcePaymentDetails' => in_array($company->country_code, ['FR', 'TN', 'IT'], true),
            'formatMoney' => fn (string|float|null $amount) => $this->formatMoney($amount, $currency, $locale),
            'formatDate' => fn (Carbon|string|null $date) => $this->formatDate($date, $locale),
            'formatDateTime' => fn (Carbon|string|null $date) => $this->formatDateTime($date, $locale),
            'formatNumber' => fn (string|float|null $number, int $decimals = 2) => $this->formatNumber($number, $decimals, $locale),
            // QR token for receipt lookup at refund time (null when no active signing key).
            'qrToken' => $qrToken,
            'qrSvg' => $qrSvg,
            // For refund receipts: original receipt's QR for traceability (spec §6.6).
            'originalQrToken' => $originalQrToken,
            'originalQrSvg' => $originalQrSvg,
        ];
    }

    /**
     * Format money amount with currency.
     */
    private function formatMoney(string|float|null $amount, string $currency, string $locale): string
    {
        if ($amount === null) {
            return number_format(0, $this->scale(), '.', '');
        }

        $amount = is_string($amount) ? (float) $amount : $amount;

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $result = $formatter->formatCurrency($amount, $currency);

        return $result !== false ? $result : number_format($amount, $this->scale()).' '.$currency;
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

        /** @var Carbon $localizedCarbon */
        $localizedCarbon = $carbon->locale($locale);

        return $localizedCarbon->isoFormat('L');
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

        /** @var Carbon $localizedCarbon */
        $localizedCarbon = $carbon->locale($locale);

        return $localizedCarbon->isoFormat('L LT');
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

    /**
     * Render a QR token string as an inline SVG suitable for embedding in a PDF.
     *
     * Uses the installed endroid/qr-code library. The SVG writer produces compact,
     * self-contained XML that dompdf can embed without external resources.
     */
    private function renderQrSvg(string $token): string
    {
        $qrCode = new QrCode(
            data: $token,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 120,
            margin: 4,
        );

        $writer = new SvgWriter;
        $result = $writer->write($qrCode, options: [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true]);

        return $result->getString();
    }
}
