<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Shared\Domain\CurrencyScale;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Get dashboard statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $currentMonthStart = Carbon::now()->startOfMonth();
        $previousMonthStart = Carbon::now()->subMonth()->startOfMonth();
        $previousMonthEnd = Carbon::now()->subMonth()->endOfMonth();

        // Revenue calculation (posted invoices)
        $currentRevenue = Document::where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Posted)
            ->where('document_date', '>=', $currentMonthStart)
            ->sum('total');

        $previousRevenue = Document::where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Posted)
            ->whereBetween('document_date', [$previousMonthStart, $previousMonthEnd])
            ->sum('total');

        $revenueChange = $previousRevenue > 0
            ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 1)
            : 0;

        // Invoice stats
        $totalInvoices = Document::where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->count();

        $pendingInvoices = Document::where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->whereIn('status', [DocumentStatus::Draft, DocumentStatus::Confirmed])
            ->count();

        $overdueInvoices = Document::where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Posted)
            ->whereNotNull('due_date')
            ->where('due_date', '<', Carbon::now())
            ->count();

        // Partner stats
        $totalPartners = Partner::where('company_id', $companyId)->count();

        $newPartnersThisMonth = Partner::where('company_id', $companyId)
            ->where('created_at', '>=', $currentMonthStart)
            ->count();

        // Payment stats
        $paymentsReceived = '0.000';
        $paymentsPending = '0.000';

        if (class_exists(Payment::class)) {
            try {
                $incomingPaymentTypes = array_values(array_map(
                    static fn (PaymentType $type): string => $type->value,
                    array_filter(
                        PaymentType::cases(),
                        static fn (PaymentType $type): bool => $type->isIncoming()
                    )
                ));

                $paymentsReceived = $this->sumDecimalStrings(
                    Payment::query()
                        ->where('company_id', $companyId)
                        ->where('status', PaymentStatus::Completed->value)
                        ->whereIn('payment_type', $incomingPaymentTypes)
                        ->where('payment_date', '>=', $currentMonthStart->toDateString())
                        ->pluck('amount')
                        ->all()
                );

                $postedInvoiceTotal = $this->sumDecimalStrings(
                    Document::where('company_id', $companyId)
                        ->where('type', DocumentType::Invoice)
                        ->where('status', DocumentStatus::Posted)
                        ->pluck('total')
                        ->all()
                );

                $paymentsPending = bcsub($postedInvoiceTotal, $paymentsReceived, 3);

                if (bccomp($paymentsPending, '0', 3) < 0) {
                    $paymentsPending = '0.000';
                }
            } catch (\Exception $e) {
                // Payments table might not exist yet
            }
        }

        return response()->json([
            'data' => [
                'revenue' => [
                    'current' => (float) $currentRevenue,
                    'previous' => (float) $previousRevenue,
                    'change' => $revenueChange,
                ],
                'invoices' => [
                    'total' => $totalInvoices,
                    'pending' => $pendingInvoices,
                    'overdue' => $overdueInvoices,
                ],
                'partners' => [
                    'total' => $totalPartners,
                    'newThisMonth' => $newPartnersThisMonth,
                ],
                'payments' => [
                    'received' => $paymentsReceived,
                    'pending' => $paymentsPending,
                ],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * @param  array<int, string|null>  $amounts
     * @return numeric-string
     */
    private function sumDecimalStrings(array $amounts): string
    {
        $total = CurrencyScale::bcformatStrict('0', 3);

        foreach ($amounts as $amount) {
            if ($amount === null) {
                continue;
            }

            $total = bcadd($total, CurrencyScale::bcformatStrict($amount, 3), 3);
        }

        return CurrencyScale::bcformatStrict($total, 3);
    }
}
