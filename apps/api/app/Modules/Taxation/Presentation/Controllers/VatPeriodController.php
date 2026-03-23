<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Application\Services\VatPeriodManagementService;
use App\Modules\Taxation\Application\Services\VatReportGenerationService;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Presentation\Requests\GenerateVatPeriodsRequest;
use App\Modules\Taxation\Presentation\Resources\VatPeriodResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class VatPeriodController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly VatPeriodManagementService $periodManagementService,
        private readonly VatReportGenerationService $reportGenerationService,
    ) {}

    /**
     * List VAT periods for the current company.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $company = $this->companyContext->requireCompany();

        $query = VatPeriod::query()
            ->forCompany($company->id)
            ->orderBy('period_start', 'desc');

        if ($request->has('year')) {
            $query->forYear((int) $request->input('year'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        return VatPeriodResource::collection($query->get());
    }

    /**
     * Show a single VAT period.
     */
    public function show(string $id): VatPeriodResource
    {
        $company = $this->companyContext->requireCompany();
        $period = VatPeriod::query()->forCompany($company->id)->findOrFail($id);

        return new VatPeriodResource($period);
    }

    /**
     * Generate VAT periods for a given year.
     */
    public function generate(GenerateVatPeriodsRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $year = (int) $request->validated('year');

        $strategy = $this->reportGenerationService->resolveStrategy($company->country_code);

        $periods = $this->periodManagementService->generatePeriods(
            $company->id,
            $company->country_code,
            $year,
            $strategy,
            $company->fiscal_year_start_month,
        );

        $periodModels = VatPeriod::query()
            ->forCompany($company->id)
            ->forYear($year)
            ->orderBy('period_start')
            ->get();

        return response()->json([
            'data' => VatPeriodResource::collection($periodModels),
        ], 201);
    }

    /**
     * Close a VAT period (snapshot data).
     */
    public function close(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $period = VatPeriod::query()->forCompany($company->id)->findOrFail($id);

        try {
            $periodData = $this->periodManagementService->closePeriod(
                $period,
                $request->input('notes'),
                (string) $request->user()?->id,
            );

            return response()->json([
                'data' => $periodData->toArray(),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reopen a closed VAT period.
     */
    public function reopen(string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $period = VatPeriod::query()->forCompany($company->id)->findOrFail($id);

        try {
            $periodData = $this->periodManagementService->reopenPeriod($period);

            return response()->json([
                'data' => $periodData->toArray(),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * File a VAT period with the tax authority.
     */
    public function file(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $period = VatPeriod::query()->forCompany($company->id)->findOrFail($id);

        try {
            $periodData = $this->periodManagementService->filePeriod(
                $period,
                $request->input('filing_reference'),
                (string) $request->user()?->id,
            );

            return response()->json([
                'data' => $periodData->toArray(),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
