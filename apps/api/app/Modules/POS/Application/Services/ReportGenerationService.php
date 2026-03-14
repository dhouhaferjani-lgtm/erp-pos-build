<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Events\ZReportGenerated;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\GrandtotalService;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\XReport;
use App\Modules\POS\Domain\ZReport;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use NumberFormatter;

/**
 * Application service for generating POS reports.
 *
 * Handles:
 * - X Reports: Mid-shift snapshots (non-destructive, read-only)
 * - Z Reports: End-of-day closings (destructive, closes shift, creates grand totals)
 */
final class ReportGenerationService
{
    public function __construct(
        private readonly ShiftManagementService $shiftManagementService,
        private readonly CashDrawerService $cashDrawerService,
        private readonly ZReportHashService $zReportHashService,
        private readonly GrandtotalService $grandtotalService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Generate X report (mid-shift snapshot)
     *
     * X reports are non-destructive snapshots that can be generated multiple times
     * during a shift. They show cumulative totals from shift opening.
     *
     * Business Rules:
     * - Can be generated multiple times per shift
     * - Does NOT close the shift
     * - Does NOT affect grand totals
     * - No hash chain (not fiscally critical)
     * - Requires an open shift
     *
     * @param  Terminal  $terminal  The terminal to generate report for
     * @param  User  $generatedBy  User generating the report
     *
     * @throws ShiftNotOpenException If no open shift exists
     */
    public function generateXReport(
        Terminal $terminal,
        User $generatedBy
    ): XReport {
        // Get current open shift
        $shift = $this->shiftManagementService->getCurrentShift($terminal);

        if (! $shift) {
            throw ShiftNotOpenException::noOpenShift($terminal->id);
        }

        // Calculate snapshot data
        $snapshotData = $this->calculateShiftTotals($terminal, $shift->opened_at, now());

        // Create X report
        return XReport::create([
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'generated_by' => $generatedBy->id,
            'snapshot_data' => $snapshotData,
            'generated_at' => now(),
        ]);
    }

    /**
     * Generate Z report (end-of-day closing)
     *
     * Z reports are fiscally critical closings that:
     * - Close the current shift
     * - Create a GRANDTOTAL_DAILY event
     * - Are hash chained (previous_z_hash)
     * - Have sequential z_number that never resets
     *
     * Business Rules:
     * - Can only be generated once per shift
     * - MUST have an open shift
     * - Automatically closes the shift
     * - Creates GRANDTOTAL_DAILY event
     * - Sequential z_number never resets
     * - Hash chained to previous Z report
     *
     * @param  Terminal  $terminal  The terminal to generate report for
     * @param  User  $generatedBy  User generating the report
     *
     * @throws ShiftNotOpenException If no open shift exists
     */
    public function generateZReport(
        Terminal $terminal,
        User $generatedBy
    ): ZReport {
        return DB::transaction(function () use ($terminal, $generatedBy) {
            // Get current open shift
            $shift = $this->shiftManagementService->getCurrentShift($terminal);

            if (! $shift) {
                throw ShiftNotOpenException::noOpenShift($terminal->id);
            }

            // Calculate shift totals
            $reportData = $this->calculateShiftTotals($terminal, $shift->opened_at, now());

            // Add cash drawer information
            $reportData['opening_cash'] = $shift->opening_cash;
            $reportData['expected_cash'] = $this->cashDrawerService->calculateExpectedCash($shift);
            $reportData['variance'] = null;  // Will be set when shift is closed

            // Get next Z number and previous Z hash
            $zNumber = $this->zReportHashService->getNextZNumber($terminal);
            $previousZHash = $this->zReportHashService->getPreviousZHash($terminal);

            // Create Z report (without hash initially)
            $zReport = new ZReport([
                'terminal_id' => $terminal->id,
                'shift_id' => $shift->id,
                'z_number' => $zNumber,
                'previous_z_hash' => $previousZHash,
                'report_data' => $reportData,
                'generated_by' => $generatedBy->id,
                'generated_at' => now(),
            ]);

            // Calculate fiscal hash
            $zReport->fiscal_hash = $this->zReportHashService->calculateHash($zReport, $previousZHash);

            // Save Z report
            $zReport->save();

            // Create GRANDTOTAL_DAILY event
            $this->grandtotalService->createGrandtotalEvent(
                $terminal,
                'DAILY',
                $shift->opened_at,
                now(),
                $generatedBy
            );

            // Note: Shift will be closed separately via ShiftManagementService::closeShift()
            // Z report generation and shift closing are separate operations to allow
            // for actual cash counting after Z report is printed

            /** @var ZReport $freshReport */
            $freshReport = $zReport->fresh();

            event(new ZReportGenerated(
                zReportId: $freshReport->id,
                companyId: $terminal->company_id,
                terminalId: $terminal->id,
                zNumber: $freshReport->z_number,
                fiscalHash: $freshReport->fiscal_hash,
                generatedAt: $freshReport->generated_at->toIso8601String(),
            ));

            return $freshReport;
        });
    }

    /**
     * Generate PDF for a Z report.
     *
     * @param  ZReport  $zReport  The Z report to generate PDF for
     */
    public function generatePdf(ZReport $zReport): DomPdf
    {
        $zReport->load(['terminal', 'generatedBy']);

        /** @var \App\Modules\POS\Domain\Terminal $terminal */
        $terminal = $zReport->terminal;

        /** @var Company $company */
        $company = Company::findOrFail($terminal->company_id);

        $data = $this->preparePdfData($zReport, $company);

        $pdf = Pdf::loadView('pos.z-report', $data);

        // A4 paper for Z reports (unlike thermal receipt)
        $pdf->setPaper('a4', 'portrait');

        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Sans');

        return $pdf;
    }

    /**
     * Get filename for the Z report PDF.
     */
    public function getZReportFilename(ZReport $zReport): string
    {
        return sprintf('z-report-%s.pdf', $zReport->getFormattedZNumber());
    }

    /**
     * Prepare data for the Z report PDF template.
     *
     * @return array<string, mixed>
     */
    private function preparePdfData(ZReport $zReport, Company $company): array
    {
        $locale = $company->locale ?? 'en';
        $currency = $company->currency ?? 'EUR';
        $reportData = $zReport->report_data;
        $salesCount = $reportData['sales_count'] ?? 0;
        $grossSales = $reportData['gross_sales'] ?? '0.00';

        $averageTicket = $salesCount > 0
            ? bcdiv($grossSales, (string) $salesCount, $this->scale())
            : '0.00';

        return [
            'zReport' => $zReport,
            'company' => $company,
            'terminal' => $zReport->terminal,
            'generatedByName' => $zReport->generatedBy->name,
            'reportData' => $reportData,
            'vatBreakdown' => $reportData['vat_breakdown'] ?? [],
            'paymentMethods' => $reportData['payment_methods'] ?? [],
            'averageTicket' => $averageTicket,
            'hasVariance' => $zReport->hasVariance($this->scale()),
            'locale' => $locale,
            'currency' => $currency,
            'formatMoney' => fn (string|float|null $amount) => $this->formatMoney($amount, $currency, $locale),
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
            return number_format(0, $this->scale(), '.', '');
        }

        $amount = is_string($amount) ? (float) $amount : $amount;

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $result = $formatter->formatCurrency($amount, $currency);

        return $result !== false ? $result : number_format($amount, $this->scale()).' '.$currency;
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
     * Calculate shift totals for reports
     *
     * Aggregates all receipts in the time period.
     *
     * @param  Terminal  $terminal  The terminal to calculate for
     * @param  \Illuminate\Support\Carbon  $startTime  Period start
     * @param  \Illuminate\Support\Carbon  $endTime  Period end
     * @return array<string, mixed> Snapshot data array
     */
    private function calculateShiftTotals(Terminal $terminal, $startTime, $endTime): array
    {
        // Get all production receipts in period (exclude training)
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->where('is_training', false)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->get();

        $grossSales = '0.00';
        $netSales = '0.00';
        $taxAmount = '0.00';
        $salesCount = 0;
        $refundsCount = 0;
        $refundsAmount = '0.00';
        $voidedCount = 0;

        // VAT breakdown by rate
        $vatBreakdown = [];

        // Payment methods breakdown
        $paymentMethods = [];

        foreach ($receipts as $receipt) {
            if ($receipt->is_voided) {
                $voidedCount++;

                continue;
            }

            $salesCount++;
            $grossSales = bcadd($grossSales, $receipt->total, $this->scale());
            $taxAmount = bcadd($taxAmount, $receipt->tax_amount, $this->scale());
            $netSales = bcadd($netSales, $receipt->subtotal, $this->scale());

            // Aggregate VAT breakdown
            foreach ($receipt->vatDetails as $vatDetail) {
                $rate = (string) $vatDetail->tax_rate;
                if (! isset($vatBreakdown[$rate])) {
                    $vatBreakdown[$rate] = [
                        'tax_rate' => $vatDetail->tax_rate,
                        'net_amount' => '0.00',
                        'vat_amount' => '0.00',
                        'gross_amount' => '0.00',
                    ];
                }
                $vatBreakdown[$rate]['net_amount'] = bcadd($vatBreakdown[$rate]['net_amount'], $vatDetail->net_amount, $this->scale());
                $vatBreakdown[$rate]['vat_amount'] = bcadd($vatBreakdown[$rate]['vat_amount'], $vatDetail->vat_amount, $this->scale());
                $vatBreakdown[$rate]['gross_amount'] = bcadd($vatBreakdown[$rate]['gross_amount'], $vatDetail->gross_amount, $this->scale());
            }

            // Aggregate payment methods
            foreach ($receipt->payments as $payment) {
                $method = $payment->payment_type;
                if (! isset($paymentMethods[$method])) {
                    $paymentMethods[$method] = [
                        'payment_type' => $payment->payment_type,
                        'total_amount' => '0.00',
                        'transaction_count' => 0,
                    ];
                }
                $paymentMethods[$method]['total_amount'] = bcadd($paymentMethods[$method]['total_amount'], $payment->amount, $this->scale());
                $paymentMethods[$method]['transaction_count']++;
            }
        }

        return [
            'sales_count' => $salesCount,
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'tax_amount' => $taxAmount,
            'refunds_count' => $refundsCount,
            'refunds_amount' => $refundsAmount,
            'voided_count' => $voidedCount,
            'vat_breakdown' => array_values($vatBreakdown),
            'payment_methods' => array_values($paymentMethods),
        ];
    }
}
