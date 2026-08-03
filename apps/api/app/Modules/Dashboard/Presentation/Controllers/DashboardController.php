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
use Illuminate\Database\Eloquent\Builder;
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
        // M1 fix (2026-08-03 gate): subMonth() OVERFLOWS on a day-of-month that doesn't exist
        // in the previous month (e.g. Mar 31 -> "Feb 31" rolls over into March), collapsing the
        // previous-month window onto the current month on ~6 dates/year. subMonthNoOverflow()
        // clamps to the previous month's last day instead of rolling over.
        $previousMonthStart = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $previousMonthEnd = Carbon::now()->subMonthNoOverflow()->endOfMonth();

        // Revenue calculation. Ruling (docs/superpowers/tickets/2026-08-02-dashboard-stats-status-buckets-and-float-sum.md):
        // revenue = whereIn(status, [Posted, Paid]) — a Paid invoice is still revenue, and a
        // refund's Paid -> Posted revert must NOT move revenue since both statuses are already
        // in the base. Summed via SQL SUM cast to text (precision rule 19 — no PHP float) and
        // with no pluck() (2026-08-03 gate M4 — pluck() on a decimal-cast column hydrates a
        // full Eloquent model per row; SUM avoids both the float and the row hydration).
        $revenueStatuses = [DocumentStatus::Posted, DocumentStatus::Paid];

        $currentRevenue = $this->sumColumnAsString(
            Document::where('company_id', $companyId)
                ->where('type', DocumentType::Invoice)
                ->whereIn('status', $revenueStatuses)
                ->where('document_date', '>=', $currentMonthStart),
            'total'
        );

        $previousRevenue = $this->sumColumnAsString(
            Document::where('company_id', $companyId)
                ->where('type', DocumentType::Invoice)
                ->whereIn('status', $revenueStatuses)
                ->whereBetween('document_date', [$previousMonthStart, $previousMonthEnd]),
            'total'
        );

        // change stays a percentage, represented as a 2dp bc string, or null when there is no
        // meaningful baseline to compare against. AMENDED ruling (2026-08-03 gate M2): a
        // previous <= 0 (no previous-period revenue at all, or a negative one from a data
        // anomaly / rebate) yields null, not a silently wrong "0.00" — mirroring
        // ExpenseAnalyticsService::generate()'s real bccomp-guarded null convention, applied
        // here with the explicit <=0 (not just ==0) cutoff the ruling calls for. The web tile
        // renders no arrow + an em-dash on null (same convention as ExpenseAnalyticsPage's
        // mom_delta_percent tile).
        $revenueChange = null;

        if (bccomp($previousRevenue, '0', 3) > 0) {
            $intermediateScale = 10;
            $delta = bcsub($currentRevenue, $previousRevenue, $intermediateScale);
            $ratio = bcdiv($delta, $previousRevenue, $intermediateScale);
            $revenueChange = CurrencyScale::bcround(bcmul($ratio, '100', $intermediateScale), 2);
        }

        // Invoice stats
        $totalInvoices = Document::where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->count();

        $pendingInvoices = Document::where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->whereIn('status', [DocumentStatus::Draft, DocumentStatus::Confirmed])
            ->count();

        // Overdue deliberately stays Posted-only (NOT whereIn([Posted, Paid]) like revenue
        // above) — a Paid invoice past its due date is settled, not overdue. Corollary: a
        // refund reverting Paid -> Posted CAN legitimately re-enter this bucket if the
        // invoice is past due — that is correct business reality, not a regression.
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

        // AMENDED ruling 3 (2026-08-03 gate H1): pending = SUM(balance_due) over the SAME
        // Posted∪Paid base as revenue — authoritative and refund-aware (balance_due is
        // decremented by treasury allocations and reopened by refund-unwind), matching what
        // invoice detail views already show. Deliberately NOT paymentsReceived-derived:
        // subtracting a month-scoped paymentsReceived from an all-time invoice base was
        // arithmetically invalid — it silently counted every historically-settled invoice
        // (balance_due already 0) as still-pending money, roughly doubling the figure on the
        // launch tenant. See ticket § RESOLUTION / AMENDMENTS.
        $paymentsPending = $this->sumColumnAsString(
            Document::where('company_id', $companyId)
                ->where('type', DocumentType::Invoice)
                ->whereIn('status', $revenueStatuses),
            'balance_due'
        );

        // Payment stats
        $paymentsReceived = '0.000';

        if (class_exists(Payment::class)) {
            try {
                // L4 (2026-08-03 gate, in-scope per the ticket's own "refunds move the
                // dashboard the wrong way" premise): completed REFUNDS this month must net
                // against paymentsReceived. Refund `Payment` rows always carry a NEGATIVE
                // `amount` by convention (PaymentRefundService::refundPayment/partialRefund/
                // the proration writer — 'amount' => bcmul($original, '-1', $scale)), so
                // including PaymentType::Refund alongside the incoming types and summing
                // nets them automatically — no sign-flipping needed here.
                $receivedPaymentTypes = array_values(array_map(
                    static fn (PaymentType $type): string => $type->value,
                    array_filter(
                        PaymentType::cases(),
                        static fn (PaymentType $type): bool => $type->isIncoming() || $type === PaymentType::Refund
                    )
                ));

                $paymentsReceived = $this->sumDecimalStrings(
                    Payment::query()
                        ->where('company_id', $companyId)
                        ->where('status', PaymentStatus::Completed->value)
                        ->whereIn('payment_type', $receivedPaymentTypes)
                        ->where('payment_date', '>=', $currentMonthStart->toDateString())
                        ->pluck('amount')
                        ->all()
                );
            } catch (\Exception $e) {
                // Payments table might not exist yet
            }
        }

        return response()->json([
            'data' => [
                'revenue' => [
                    'current' => $currentRevenue,
                    'previous' => $previousRevenue,
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

    /**
     * Sum a decimal column via SQL `SUM(...)::text` — no PHP float round-trip (precision
     * rule 19) and no per-row Eloquent model hydration (2026-08-03 gate M4; `pluck()` on a
     * `decimal:*`-cast column does not take Laravel's cheap column-array path, it hydrates a
     * full model per row). Mirrors the `CAST(COALESCE(SUM(...), 0) AS TEXT)` pattern already
     * used at `GeneralLedgerService::availableAdvanceCredit()` — `first()` + attribute read
     * rather than `value()`, since `value()`'s column param is typed against real model
     * properties and `total_sum` is a raw-select alias, not a column. $column is always a
     * compile-time literal from this class, never request-controlled.
     *
     * @param  Builder<Document>  $query
     * @return numeric-string
     */
    private function sumColumnAsString(Builder $query, string $column): string
    {
        /** @var object{total_sum: string|null}|null $result */
        $result = $query->selectRaw("CAST(COALESCE(SUM({$column}), 0) AS TEXT) as total_sum")->first();

        return CurrencyScale::bcformatStrict($result->total_sum ?? '0', 3);
    }
}
