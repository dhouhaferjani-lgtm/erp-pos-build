<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\Payment;
use App\Shared\Domain\CurrencyScale;

/**
 * Service for generating aged receivables reports
 *
 * Tracks overdue invoices by aging buckets:
 * - Current (not yet due)
 * - 1-30 days overdue
 * - 31-60 days overdue
 * - 61-90 days overdue
 * - 90+ days overdue
 */
class AgedReceivablesService
{
    /**
     * Generate aged receivables report for a company
     *
     * @param  string|null  $partnerId  Filter by specific partner
     * @param  string|null  $asOfDate  Calculate aging as of this date (default: today)
     * @return array<string, mixed>
     */
    public function generateReport(
        string $companyId,
        ?string $partnerId = null,
        ?string $asOfDate = null
    ): array {
        $asOfDate = $asOfDate ?? now()->toDateString();
        $asOfDateTime = new \DateTime($asOfDate);

        // Get all posted invoices with outstanding balance
        $query = Document::where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->where('status', 'posted')
            ->whereRaw('balance_due > 0')
            ->with(['partner']);

        if ($partnerId !== null) {
            $query->where('partner_id', $partnerId);
        }

        $invoices = $query->orderBy('partner_id')
            ->orderBy('due_date')
            ->get();

        // Initialize summary totals
        $summary = [
            'current' => '0.00',
            'days_1_30' => '0.00',
            'days_31_60' => '0.00',
            'days_61_90' => '0.00',
            'days_over_90' => '0.00',
        ];

        // Group invoices by partner
        /** @var array<string, array<string, mixed>> $byPartner */
        $byPartner = [];
        $totalOutstanding = '0.00';

        foreach ($invoices as $invoice) {
            $partnerId = $invoice->partner_id;
            $partnerName = $invoice->partner->name ?? 'Unknown Partner';

            // Calculate days overdue
            $daysOverdue = 0;
            if ($invoice->due_date !== null) {
                $dueDate = new \DateTime($invoice->due_date->toDateString());
                $interval = $dueDate->diff($asOfDateTime);
                $daysOverdue = $asOfDateTime > $dueDate ? (int) $interval->days : 0;
            }

            // Determine aging bucket
            $agingBucket = $this->getAgingBucket($daysOverdue);

            // Get outstanding amount
            $outstanding = $invoice->balance_due ?? $invoice->total ?? '0.00';
            $totalOutstanding = bcadd($totalOutstanding, $outstanding, 2);

            // Initialize partner entry if needed
            if (! isset($byPartner[$partnerId])) {
                $byPartner[$partnerId] = [
                    'partner_id' => $partnerId,
                    'partner_name' => $partnerName,
                    'total_outstanding' => '0.00',
                    'current' => '0.00',
                    'days_1_30' => '0.00',
                    'days_31_60' => '0.00',
                    'days_61_90' => '0.00',
                    'days_over_90' => '0.00',
                    'invoices' => [],
                ];
            }

            // Add to partner totals
            /** @var numeric-string $partnerOutstanding */
            $partnerOutstanding = $byPartner[$partnerId]['total_outstanding'];
            $byPartner[$partnerId]['total_outstanding'] = bcadd(
                $partnerOutstanding,
                $outstanding,
                2
            );
            /** @var numeric-string $bucketAmount */
            $bucketAmount = $byPartner[$partnerId][$agingBucket];
            $byPartner[$partnerId][$agingBucket] = bcadd(
                $bucketAmount,
                $outstanding,
                2
            );

            // Add to summary totals
            $summary[$agingBucket] = bcadd($summary[$agingBucket], $outstanding, 2);

            // Add invoice details
            $byPartner[$partnerId]['invoices'][] = [
                'id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'document_date' => $invoice->document_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'days_overdue' => $daysOverdue,
                'total' => CurrencyScale::bcformat($invoice->total, 2),
                'outstanding' => CurrencyScale::bcformat($outstanding, 2),
                'aging_bucket' => $agingBucket,
            ];
        }

        // Sort partners by total outstanding (descending)
        /** @var list<array<string, mixed>> $partnerList */
        $partnerList = array_values($byPartner);
        usort($partnerList, fn ($a, $b) => bccomp($b['total_outstanding'], $a['total_outstanding'], 2));

        /** @var array<string, mixed> */
        return [
            'as_of_date' => $asOfDate,
            'total_outstanding' => CurrencyScale::bcformat($totalOutstanding, 2),
            'summary' => $summary,
            'by_partner' => $partnerList,
        ];
    }

    /**
     * Get customer statement for a specific partner
     *
     * Shows all transactions: invoices, payments, credit notes
     *
     * @return array{
     *     partner_id: string,
     *     partner_name: string,
     *     from_date: string,
     *     to_date: string,
     *     opening_balance: string,
     *     closing_balance: string,
     *     transactions: array<int, array{
     *         date: string,
     *         type: string,
     *         document_number: string,
     *         description: string,
     *         debit: string,
     *         credit: string,
     *         balance: string
     *     }>
     * }
     */
    public function generateCustomerStatement(
        string $companyId,
        string $partnerId,
        string $fromDate,
        string $toDate
    ): array {
        // Get partner
        $partner = Partner::findOrFail($partnerId);

        // Calculate opening balance (all invoices before fromDate)
        /** @var numeric-string $openingBalance */
        $openingBalance = (string) (Document::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('type', DocumentType::Invoice)
            ->where('status', 'posted')
            ->where('document_date', '<', $fromDate)
            ->sum('balance_due'));

        // Get all transactions in date range
        $transactions = [];
        $runningBalance = $openingBalance;

        // Get invoices
        $invoices = Document::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('type', DocumentType::Invoice)
            ->where('status', 'posted')
            ->whereBetween('document_date', [$fromDate, $toDate])
            ->orderBy('document_date')
            ->orderBy('document_number')
            ->get();

        foreach ($invoices as $invoice) {
            /** @var numeric-string $amount */
            $amount = $invoice->total ?? '0.00';
            $runningBalance = bcadd($runningBalance, $amount, 2);

            $transactions[] = [
                'date' => $invoice->document_date->toDateString(),
                'type' => 'Invoice',
                'document_number' => $invoice->document_number,
                'description' => 'Invoice',
                'debit' => CurrencyScale::bcformat($amount, 2),
                'credit' => '0.00',
                'balance' => CurrencyScale::bcformat($runningBalance, 2),
            ];
        }

        // Get payments
        $payments = Payment::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->whereBetween('payment_date', [$fromDate, $toDate])
            ->with('allocations')
            ->orderBy('payment_date')
            ->get();

        foreach ($payments as $payment) {
            /** @var numeric-string $amount */
            $amount = $payment->amount ?? '0.00';
            $runningBalance = bcsub($runningBalance, $amount, 2);

            $transactions[] = [
                'date' => $payment->payment_date->toDateString(),
                'type' => 'Payment',
                'document_number' => $payment->reference ?? 'Payment',
                'description' => 'Payment received',
                'debit' => '0.00',
                'credit' => CurrencyScale::bcformat($amount, 2),
                'balance' => CurrencyScale::bcformat($runningBalance, 2),
            ];
        }

        // Get credit notes
        $creditNotes = Document::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('type', DocumentType::CreditNote)
            ->where('status', 'posted')
            ->whereBetween('document_date', [$fromDate, $toDate])
            ->orderBy('document_date')
            ->get();

        foreach ($creditNotes as $creditNote) {
            /** @var numeric-string $amount */
            $amount = $creditNote->total ?? '0.00';
            $runningBalance = bcsub($runningBalance, $amount, 2);

            $transactions[] = [
                'date' => $creditNote->document_date->toDateString(),
                'type' => 'Credit Note',
                'document_number' => $creditNote->document_number,
                'description' => 'Credit note',
                'debit' => '0.00',
                'credit' => CurrencyScale::bcformat($amount, 2),
                'balance' => CurrencyScale::bcformat($runningBalance, 2),
            ];
        }

        // Sort all transactions by date
        usort($transactions, fn ($a, $b) => strcmp($a['date'], $b['date']));

        // Recalculate balances with correct order
        $runningBalance = $openingBalance;
        foreach ($transactions as &$transaction) {
            $debit = (float) $transaction['debit'];
            $credit = (float) $transaction['credit'];
            $runningBalance = bcadd(bcsub((string) $runningBalance, (string) $credit, 2), (string) $debit, 2);
            $transaction['balance'] = CurrencyScale::bcformat($runningBalance, 2);
        }

        return [
            'partner_id' => $partnerId,
            'partner_name' => $partner->name,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'opening_balance' => CurrencyScale::bcformat($openingBalance, 2),
            'closing_balance' => CurrencyScale::bcformat($runningBalance, 2),
            'transactions' => $transactions,
        ];
    }

    /**
     * Get overdue invoices summary
     *
     * @return array{
     *     total_overdue: string,
     *     count: int,
     *     by_severity: array{
     *         critical: array{count: int, amount: string},
     *         high: array{count: int, amount: string},
     *         medium: array{count: int, amount: string},
     *         low: array{count: int, amount: string}
     *     }
     * }
     */
    public function getOverdueSummary(string $companyId): array
    {
        $today = now()->startOfDay();

        $overdueInvoices = Document::where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->where('status', 'posted')
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->whereRaw('balance_due > 0')
            ->get();

        $totalOverdue = '0.00';
        $bySeverity = [
            'critical' => ['count' => 0, 'amount' => '0.00'], // 90+ days
            'high' => ['count' => 0, 'amount' => '0.00'],     // 60-89 days
            'medium' => ['count' => 0, 'amount' => '0.00'],   // 30-59 days
            'low' => ['count' => 0, 'amount' => '0.00'],      // 1-29 days
        ];

        foreach ($overdueInvoices as $invoice) {
            $daysOverdue = $today->diffInDays($invoice->due_date);
            $outstanding = $invoice->balance_due ?? '0.00';
            $totalOverdue = bcadd($totalOverdue, $outstanding, 2);

            $severity = match (true) {
                $daysOverdue >= 90 => 'critical',
                $daysOverdue >= 60 => 'high',
                $daysOverdue >= 30 => 'medium',
                default => 'low',
            };

            $bySeverity[$severity]['count']++;
            $bySeverity[$severity]['amount'] = bcadd(
                $bySeverity[$severity]['amount'],
                $outstanding,
                2
            );
        }

        return [
            'total_overdue' => CurrencyScale::bcformat($totalOverdue, 2),
            'count' => $overdueInvoices->count(),
            'by_severity' => $bySeverity,
        ];
    }

    /**
     * Determine aging bucket based on days overdue
     */
    private function getAgingBucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'days_1_30',
            $daysOverdue <= 60 => 'days_31_60',
            $daysOverdue <= 90 => 'days_61_90',
            default => 'days_over_90',
        };
    }
}
