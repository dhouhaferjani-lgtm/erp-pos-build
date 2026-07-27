<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationScopeBoundary;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Expense\Application\DTOs\AnalyticsFilters;
use App\Modules\Expense\Application\Services\ExpenseAnalyticsService;
use App\Modules\Expense\Presentation\Requests\ExpenseAnalyticsRequest;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;

final class ExpenseAnalyticsController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ExpenseAnalyticsService $analyticsService,
        private readonly LocationScopeResolver $locationScopeResolver,
        private readonly LocationScopeBoundary $locationScopeBoundary,
    ) {}

    public function __invoke(ExpenseAnalyticsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $companyId = $this->companyContext->requireCompanyId();
        $effective = $this->locationScopeResolver->resolve($user, $this->requestedLocationIds($validated['location_ids'] ?? null), null);
        $locationIds = $this->locationScopeBoundary->isUnrestricted($companyId, $effective) ? [] : $effective;

        $filters = new AnalyticsFilters(
            date_from: (string) $validated['date_from'],
            date_to: (string) $validated['date_to'],
            category_id: isset($validated['category_id']) ? (string) $validated['category_id'] : null,
            status: (string) $validated['status'],
            location_ids: $locationIds,
        );

        return response()->json([
            'data' => $this->analyticsService->generate(
                $user->tenant_id,
                $companyId,
                $filters,
            ),
        ]);
    }

    /** @return list<string> */
    private function requestedLocationIds(mixed $value): array
    {
        return array_values(array_filter(
            is_array($value) ? $value : [],
            static fn (mixed $id): bool => is_string($id),
        ));
    }
}
