<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
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
            ),
        ]);
    }

    private function getCompanyId(): string
    {
        $companyId = $this->companyContext->getCompanyId();
        assert($companyId !== null, 'Company context must be set');

        return $companyId;
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
