<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers;

use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for partner balance and subledger operations.
 *
 * These endpoints allow:
 * - Viewing individual partner balances from the GL
 * - Generating partner statements
 * - Viewing subledger reports (receivables, payables)
 * - Reconciling subledger against control accounts
 * - Refreshing cached partner balances
 *
 * api.accounting (round-2 Codex remediation): every method validates the
 * URL `{companyId}` against the authenticated user's `UserCompanyMembership`
 * BEFORE invoking the service. This closes the route-driven cross-tenant
 * exploit Codex round-1 second-layer flagged on api.accounting.004/005:
 * a tenant-A user can no longer hit `/api/v1/companies/{tenant-B-company}/...`
 * because the membership check short-circuits with 404. The service-tier
 * `where('company_id', $companyId)` scope is then sufficient because the
 * controller has already verified the auth user owns that company.
 */
class PartnerBalanceController extends Controller
{
    public function __construct(
        private readonly PartnerBalanceService $balanceService
    ) {}

    /**
     * GET /api/v1/companies/{companyId}/partners/{partnerId}/balance
     */
    public function show(Request $request, string $companyId, string $partnerId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $purpose = null;
        /** @var string|null $purposeQuery */
        $purposeQuery = $request->query('purpose');
        if ($purposeQuery !== null) {
            $purpose = SystemAccountPurpose::from($purposeQuery);
        }

        $balance = $this->balanceService->getPartnerBalance($companyId, $partnerId, $purpose);

        return response()->json([
            'data' => $balance,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/v1/companies/{companyId}/partners/{partnerId}/statement
     */
    public function statement(Request $request, string $companyId, string $partnerId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $purpose = null;
        /** @var string|null $purposeQuery */
        $purposeQuery = $request->query('purpose');
        if ($purposeQuery !== null) {
            $purpose = SystemAccountPurpose::from($purposeQuery);
        }

        /** @var string|null $fromDate */
        $fromDate = $request->query('from_date');
        /** @var string|null $toDate */
        $toDate = $request->query('to_date');

        $statement = $this->balanceService->getPartnerStatement(
            $companyId,
            $partnerId,
            $purpose,
            $fromDate,
            $toDate
        );

        return response()->json([
            'data' => [
                'transactions' => $statement->values(),
                'count' => $statement->count(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/v1/companies/{companyId}/subledger/receivables
     */
    public function receivables(Request $request, string $companyId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $balances = $this->balanceService->getAllPartnerBalances(
            $companyId,
            SystemAccountPurpose::CustomerReceivable
        );

        return response()->json([
            'data' => [
                'partners' => $balances->values(),
                'total' => $balances->sum('balance'),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/v1/companies/{companyId}/subledger/payables
     */
    public function payables(Request $request, string $companyId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $balances = $this->balanceService->getAllPartnerBalances(
            $companyId,
            SystemAccountPurpose::SupplierPayable
        );

        return response()->json([
            'data' => [
                'partners' => $balances->values(),
                'total' => $balances->sum('balance'),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/v1/companies/{companyId}/subledger/reconcile/{purpose}
     */
    public function reconcile(Request $request, string $companyId, string $purpose): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $purposeEnum = SystemAccountPurpose::from($purpose);
        $result = $this->balanceService->reconcileSubledger($companyId, $purposeEnum);

        return response()->json([
            'data' => $result,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/companies/{companyId}/partners/{partnerId}/balance/refresh
     */
    public function refresh(Request $request, string $companyId, string $partnerId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $this->balanceService->refreshPartnerBalance($companyId, $partnerId);

        $balance = $this->balanceService->getCachedOrCalculateBalance(
            $companyId,
            $partnerId,
            refreshIfStale: false
        );

        return response()->json([
            'data' => [
                'message' => 'Balance refreshed successfully',
                'balance' => $balance,
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/companies/{companyId}/partners/balance/refresh-all
     */
    public function refreshAll(Request $request, string $companyId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $count = $this->balanceService->refreshAllPartnerBalances($companyId);

        return response()->json([
            'data' => [
                'message' => 'All partner balances refreshed successfully',
                'partners_updated' => $count,
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * api.accounting (round-2 Codex remediation): refuse the request if the
     * authenticated user has no UserCompanyMembership row for the URL
     * `{companyId}`. Returns 404 (not 403) to avoid disclosing that the
     * companyId exists in some other tenant.
     */
    private function assertCompanyAccess(Request $request, string $companyId): void
    {
        /** @var User $user */
        $user = $request->user();

        $hasAccess = UserCompanyMembership::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->exists();

        if (! $hasAccess) {
            throw new NotFoundHttpException('Company not found.');
        }
    }
}
