<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\Services\CatalogBrowseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CatalogBrowseController extends Controller
{
    public function __construct(
        private readonly CatalogBrowseService $catalogBrowseService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function manufacturers(Request $request): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $data = $this->catalogBrowseService->getManufacturers($verticalScope);

        return $this->respond($request, $data);
    }

    public function modelSeries(Request $request, string $manufacturerId): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $data = $this->catalogBrowseService->getModelSeries($manufacturerId, $verticalScope);

        return $this->respond($request, $data);
    }

    public function vehicles(Request $request, string $modelSeriesId): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $data = $this->catalogBrowseService->getVehicles($modelSeriesId, $verticalScope);

        return $this->respond($request, $data);
    }

    public function vehicleArticles(Request $request, string $vehicleType, string $vehicleId): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $data = $this->catalogBrowseService->getVehicleArticles($vehicleType, $vehicleId, $verticalScope);
        $companyId = $this->companyContext->requireCompanyId();

        if ($data !== null && isset($data['articles']) && is_array($data['articles'])) {
            $data['articles'] = $this->catalogBrowseService->enrichWithInventoryStatus(
                $data['articles'],
                $companyId
            );
        }

        return $this->respond($request, $data);
    }

    public function searchArticles(Request $request): JsonResponse
    {
        $params = $request->only(['article_number', 'reference_number', 'barcode', 'search']);
        /** @var array<string, string> $params */
        $verticalScope = $this->resolveVerticalScope();
        $data = $this->catalogBrowseService->searchArticles($params, $verticalScope);
        $companyId = $this->companyContext->requireCompanyId();

        if ($data !== null && isset($data['articles']) && is_array($data['articles'])) {
            $data['articles'] = $this->catalogBrowseService->enrichWithInventoryStatus(
                $data['articles'],
                $companyId
            );
        }

        return $this->respond($request, $data);
    }

    public function crossReferenceSearch(Request $request): JsonResponse
    {
        $request->validate([
            'reference_number' => ['required', 'string', 'max:200'],
        ]);

        $verticalScope = $this->resolveVerticalScope();
        $data = $this->catalogBrowseService->searchArticles([
            'reference_number' => (string) $request->input('reference_number'),
        ], $verticalScope);
        $companyId = $this->companyContext->requireCompanyId();

        if ($data !== null && isset($data['articles']) && is_array($data['articles'])) {
            $data['articles'] = $this->catalogBrowseService->enrichWithInventoryStatus(
                $data['articles'],
                $companyId
            );
        }

        return $this->respond($request, $data);
    }

    public function searchTreeRoots(Request $request): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $data = $this->catalogBrowseService->getSearchTreeRoots($verticalScope);

        return $this->respond($request, $data);
    }

    public function searchTreeChildren(Request $request, string $nodeId): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $data = $this->catalogBrowseService->getSearchTreeChildren($nodeId, $verticalScope);

        return $this->respond($request, $data);
    }

    public function searchTreeArticles(Request $request, string $nodeId): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $data = $this->catalogBrowseService->getSearchTreeArticles($nodeId, $verticalScope);
        $companyId = $this->companyContext->requireCompanyId();

        if ($data !== null && isset($data['articles']) && is_array($data['articles'])) {
            $data['articles'] = $this->catalogBrowseService->enrichWithInventoryStatus(
                $data['articles'],
                $companyId
            );
        }

        return $this->respond($request, $data);
    }

    private function resolveVerticalScope(): ?string
    {
        $company = $this->companyContext->getCompany();

        if ($company === null) {
            return null;
        }

        /** @var \App\Enums\Vertical $vertical */
        $vertical = $company->tenant->vertical;

        return $vertical->catalogScope();
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function respond(Request $request, ?array $data): JsonResponse
    {
        if ($data === null) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 502);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
