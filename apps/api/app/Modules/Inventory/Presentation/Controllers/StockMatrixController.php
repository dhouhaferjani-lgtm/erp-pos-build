<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\StockMatrixQueryService;
use App\Modules\Inventory\Application\Services\StockRebalanceQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class StockMatrixController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationScopeResolver $scopeResolver,
        private readonly StockMatrixQueryService $matrixQuery,
        private readonly StockRebalanceQueryService $rebalanceQuery,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        /** @var list<string> $requested */
        $requested = array_values(array_filter((array) $request->input('location_ids', []), static fn (mixed $id): bool => is_string($id) && $id !== ''));
        $locationIds = $this->scopeResolver->resolve($user, $requested);

        $search = trim((string) $request->query('search', ''));
        $page = max(1, $request->integer('page', 1));
        $perPage = min(max(1, $request->integer('per_page', 25)), 100);
        $includeIncoming = str_contains((string) $request->query('include', ''), 'incoming');

        return response()->json($this->matrixQuery->matrix(
            $company->tenant_id,
            $company->id,
            $locationIds,
            $search,
            $page,
            $perPage,
            $includeIncoming,
        ));
    }

    public function rebalance(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();
        /** @var list<string> $requested */
        $requested = array_values(array_filter((array) $request->input('location_ids', []), static fn (mixed $id): bool => is_string($id) && $id !== ''));
        $locationIds = $this->scopeResolver->resolve($user, $requested);

        return response()->json($this->rebalanceQuery->rebalance($company->tenant_id, $company->id, $locationIds));
    }
}
