<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Server-side cash-position aggregation (Treasury spine Task 25).
 *
 * Replaces the FE client-side sum previously computed in
 * TreasuryOverviewPage::sumBalances() — that logic now lives here, server-side,
 * grouped by repository type with per-group and grand totals.
 *
 * Reads `payment_repositories.balance` directly. That column is port-managed
 * (Task 22: the single writer is TreasuryMovementService, enforced by a pgsql
 * trigger) and reconcile-guarded (`treasury:reconcile` freezes a repository on
 * drift) — it is the authoritative cash figure. This endpoint MUST NOT
 * recompute a position from `repository_movements`; that would silently diverge
 * from the guarded source of truth the moment the two disagree.
 */
class CashPositionController extends Controller
{
    /**
     * The three "cash" repository types the position covers. `virtual`
     * repositories (netting/suspense) are intentionally excluded — this
     * mirrors the FE's isCashRepositoryType() which the FE (Task 27) replaces.
     *
     * @var list<RepositoryType>
     */
    private const CASH_TYPES = [
        RepositoryType::CashRegister,
        RepositoryType::BankAccount,
        RepositoryType::Safe,
    ];

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $cashTypeValues = array_map(static fn (RepositoryType $type): string => $type->value, self::CASH_TYPES);

        $repositories = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('type', $cashTypeValues)
            ->orderBy('name')
            ->get();

        $scale = $this->scaleResolver->getScale($company->currency);
        $zero = CurrencyScale::bcformatStrict('0', $scale);

        $grandTotal = $zero;
        $groups = [];

        foreach (self::CASH_TYPES as $type) {
            /** @var Collection<int, PaymentRepository> $repositoriesOfType */
            $repositoriesOfType = $repositories->filter(
                static fn (PaymentRepository $repository): bool => $repository->type === $type
            )->values();

            $groupTotal = $repositoriesOfType->reduce(
                fn (string $carry, PaymentRepository $repository): string => bcadd(
                    $carry,
                    CurrencyScale::bcformatStrict((string) $repository->balance, $scale),
                    $scale,
                ),
                $zero,
            );

            $grandTotal = bcadd($grandTotal, $groupTotal, $scale);

            $groups[] = [
                'type' => $type->value,
                'total' => $groupTotal,
                'repositories' => $repositoriesOfType->map(fn (PaymentRepository $repository): array => [
                    'id' => $repository->id,
                    'code' => $repository->code,
                    'name' => $repository->name,
                    'balance' => CurrencyScale::bcformatStrict((string) $repository->balance, $scale),
                ])->all(),
            ];
        }

        $flows = null;
        $windowRaw = $request->query('flows_window');

        if ($windowRaw !== null) {
            if (! is_string($windowRaw)
                || ! ctype_digit($windowRaw)
                || (int) $windowRaw < 1
                || (int) $windowRaw > 90) {
                throw new \DomainException('flows_window must be an integer between 1 and 90.');
            }

            $window = (int) $windowRaw;
            // SUM stays exact because repository_movements.amount is decimal(15,3).
            $sums = DB::table('repository_movements as m')
                ->join('payment_repositories as r', 'r.id', '=', 'm.payment_repository_id')
                ->where('r.tenant_id', $tenantId)
                ->where('r.company_id', $companyId)
                ->where('r.is_active', true)
                ->whereIn('r.type', $cashTypeValues)
                ->where('r.currency', $company->currency)
                ->where('m.occurred_at', '>=', Carbon::now()->subDays($window))
                ->selectRaw('m.direction, SUM(m.amount) AS total')
                ->groupBy('m.direction')
                ->pluck('total', 'direction');

            $flows = [
                'window_days' => $window,
                'in' => CurrencyScale::bcformatStrict((string) ($sums['in'] ?? '0'), $scale),
                'out' => CurrencyScale::bcformatStrict((string) ($sums['out'] ?? '0'), $scale),
            ];
        }

        return response()->json([
            'data' => [
                'as_of' => Carbon::now()->toIso8601String(),
                'currency' => $company->currency,
                'groups' => $groups,
                'grand_total' => $grandTotal,
                ...($flows !== null ? ['flows' => $flows] : []),
            ],
        ]);
    }
}
