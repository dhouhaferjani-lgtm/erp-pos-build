<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\PosAnalyticsService;
use App\Modules\POS\Presentation\Requests\AnalyticsRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PosAnalyticsService $analyticsService,
        private readonly LocationScopeResolver $locationScope,
    ) {}

    public function summary(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        [$from, $to] = $this->parseDates($request);

        return response()->json([
            'data' => $this->analyticsService->getSalesSummary(
                $this->getCompanyId(),
                $from,
                $to,
                $this->scopedLocationIds($request),
            ),
        ]);
    }

    public function salesByCategory(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        [$from, $to] = $this->parseDates($request);

        return response()->json([
            'data' => $this->analyticsService->getSalesByCategory(
                $this->getCompanyId(),
                $from,
                $to,
                $this->scopedLocationIds($request),
            ),
        ]);
    }

    public function salesByProduct(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        [$from, $to] = $this->parseDates($request);
        $limit = (int) $request->input('limit', 20);

        return response()->json([
            'data' => $this->analyticsService->getSalesByProduct(
                $this->getCompanyId(),
                $from,
                $to,
                $limit,
                $this->scopedLocationIds($request),
            ),
        ]);
    }

    public function salesByPeriod(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        [$from, $to] = $this->parseDates($request);
        $granularity = $request->input('granularity', 'day');

        return response()->json([
            'data' => $this->analyticsService->getSalesByTimePeriod(
                $this->getCompanyId(),
                $from,
                $to,
                $granularity,
                $this->scopedLocationIds($request),
            ),
        ]);
    }

    public function cashiers(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        [$from, $to] = $this->parseDates($request);

        return response()->json([
            'data' => $this->analyticsService->getCashierPerformance(
                $this->getCompanyId(),
                $from,
                $to,
                $this->scopedLocationIds($request),
            ),
        ]);
    }

    public function discounts(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        [$from, $to] = $this->parseDates($request);

        return response()->json([
            'data' => $this->analyticsService->getDiscountAnalysis(
                $this->getCompanyId(),
                $from,
                $to,
                $this->scopedLocationIds($request),
            ),
        ]);
    }

    public function customers(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        [$from, $to] = $this->parseDates($request);

        return response()->json([
            'data' => $this->analyticsService->getCustomerAnalytics(
                $this->getCompanyId(),
                $from,
                $to,
                $this->scopedLocationIds($request),
            ),
        ]);
    }

    public function fnb(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        [$from, $to] = $this->parseDates($request);

        return response()->json([
            'data' => $this->analyticsService->getFnbMetrics(
                $this->getCompanyId(),
                $from,
                $to,
                $this->scopedLocationIds($request),
            ),
        ]);
    }

    private function getCompanyId(): string
    {
        $companyId = $this->companyContext->getCompanyId();
        assert($companyId !== null, 'Company context must be set');

        return $companyId;
    }

    /** @return list<string> */
    private function scopedLocationIds(AnalyticsRequest $request): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $requested = $request->validated('location_ids', []);
        /** @var list<string> $requestedIds */
        $requestedIds = is_array($requested)
            ? array_values(array_filter($requested, static fn (mixed $id): bool => is_string($id)))
            : [];

        return $this->locationScope->resolve($user, $requestedIds, null);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function parseDates(AnalyticsRequest $request): array
    {
        return [
            CarbonImmutable::parse($request->validated('from')),
            CarbonImmutable::parse($request->validated('to')),
        ];
    }
}
