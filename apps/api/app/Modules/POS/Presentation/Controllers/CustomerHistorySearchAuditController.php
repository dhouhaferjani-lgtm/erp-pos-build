<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Audit list controller for customer_history_searches.
 *
 * Provides a paginated, filterable read-side view of the audit log written
 * by CustomerHistorySearchService. Each row is enriched with denormalized
 * cashier_name, terminal_name, and partner_name via SQL joins so the web
 * back-office can display a human-readable audit trail without additional
 * round-trips.
 *
 * Permission: pos.search_customer_full_history (Manager / Admin).
 */
final class CustomerHistorySearchAuditController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * GET /api/v1/pos/customer-history-searches
     *
     * Returns a paginated, descending-by-created_at list of customer history
     * search audit rows for the authenticated company.
     *
     * Query params (all optional):
     *   cashier_id    UUID
     *   terminal_id   UUID
     *   partner_id    UUID
     *   was_rejected  boolean (0/1/true/false)
     *   from_date     ISO datetime (inclusive lower bound on created_at)
     *   to_date       ISO datetime (inclusive upper bound on created_at)
     *   page          integer (default 1)
     *   per_page      integer (default 20, max 100)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();

        $perPage = min((int) ($request->query('per_page', '20')), 100);

        $query = DB::table('customer_history_searches as chs')
            ->leftJoin('users as u', 'u.id', '=', 'chs.cashier_id')
            ->leftJoin('pos_terminals as t', 't.id', '=', 'chs.terminal_id')
            ->leftJoin('partners as p', 'p.id', '=', 'chs.partner_id')
            ->where('chs.tenant_id', $user->tenant_id)
            ->where('chs.company_id', $companyId)
            ->select([
                'chs.id',
                'chs.tenant_id',
                'chs.company_id',
                'chs.cashier_id',
                'chs.terminal_id',
                'chs.partner_id',
                'chs.search_terms_hash',
                'chs.result_count',
                'chs.was_rejected',
                'chs.rejection_reason',
                'chs.created_at',
                DB::raw('u.name as cashier_name'),
                DB::raw('t.name as terminal_name'),
                DB::raw('p.name as partner_name'),
            ])
            ->orderByDesc('chs.created_at');

        if ($request->filled('cashier_id')) {
            $cashierId = $request->query('cashier_id');
            if (is_string($cashierId) && Str::isUuid($cashierId)) {
                $query->where('chs.cashier_id', $cashierId);
            }
        }

        if ($request->filled('terminal_id')) {
            $terminalId = $request->query('terminal_id');
            if (is_string($terminalId) && Str::isUuid($terminalId)) {
                $query->where('chs.terminal_id', $terminalId);
            }
        }

        if ($request->filled('partner_id')) {
            $partnerId = $request->query('partner_id');
            if (is_string($partnerId) && Str::isUuid($partnerId)) {
                $query->where('chs.partner_id', $partnerId);
            }
        }

        if ($request->has('was_rejected') && $request->query('was_rejected') !== null) {
            $wasRejected = filter_var($request->query('was_rejected'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($wasRejected !== null) {
                $query->where('chs.was_rejected', $wasRejected);
            }
        }

        if ($request->filled('from_date')) {
            $fromDate = $request->query('from_date');
            if (is_string($fromDate)) {
                $query->where('chs.created_at', '>=', $fromDate);
            }
        }

        if ($request->filled('to_date')) {
            $toDate = $request->query('to_date');
            if (is_string($toDate)) {
                $query->where('chs.created_at', '<=', $toDate);
            }
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }
}
