<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Identity\Domain\User;
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
use Illuminate\Support\Facades\DB;

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

            return $freshReport;
        });
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
        // Get all receipts in period
        $receipts = Receipt::where('terminal_id', $terminal->id)
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
