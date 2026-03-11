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
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getManufacturers($verticalScope, $countryCode);

        return $this->respond($request, $data);
    }

    public function modelSeries(Request $request, string $manufacturerId): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getModelSeries($manufacturerId, $verticalScope, $countryCode);

        return $this->respond($request, $data);
    }

    public function vehicles(Request $request, string $modelSeriesId): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $countryCode = $this->resolveCountryCode();
        $vehicleType = (string) $request->query('type', 'passenger-cars');
        $data = $this->catalogBrowseService->getVehicles($modelSeriesId, $vehicleType, $verticalScope, $countryCode);

        return $this->respond($request, $data);
    }

    public function vehicle(Request $request, string $vehicleType, string $vehicleId): JsonResponse
    {
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getVehicle($vehicleType, $vehicleId, $countryCode);

        return $this->respond($request, $data);
    }

    public function vehicleArticles(Request $request, string $vehicleType, string $vehicleId): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getVehicleArticles($vehicleType, $vehicleId, $verticalScope, $countryCode);

        return $this->respondWithArticles($request, $data);
    }

    public function searchArticles(Request $request): JsonResponse
    {
        $params = $request->only(['article_number', 'reference_number', 'barcode', 'search']);
        /** @var array<string, string> $params */
        $verticalScope = $this->resolveVerticalScope();
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->searchArticles($params, $verticalScope, $countryCode);

        return $this->respondWithArticles($request, $data);
    }

    public function article(Request $request, string $articleId): JsonResponse
    {
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getArticle($articleId, $countryCode);

        return $this->respond($request, $data);
    }

    public function articleLinkages(Request $request, string $articleId): JsonResponse
    {
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getArticleLinkages($articleId, $countryCode);

        return $this->respond($request, $data);
    }

    public function searchByCriteria(Request $request): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $countryCode = $this->resolveCountryCode();
        /** @var array<string, mixed> $criteria */
        $criteria = $request->all();
        $data = $this->catalogBrowseService->searchByCriteria($criteria, $verticalScope, $countryCode);

        return $this->respondWithArticles($request, $data);
    }

    public function criteria(Request $request): JsonResponse
    {
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getCriteria($countryCode);

        return $this->respond($request, $data);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getSuppliers($countryCode);

        return $this->respond($request, $data);
    }

    public function crossReferenceSearch(Request $request): JsonResponse
    {
        $request->validate([
            'reference_number' => ['required', 'string', 'max:200'],
        ]);

        $verticalScope = $this->resolveVerticalScope();
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->searchArticles([
            'reference_number' => (string) $request->input('reference_number'),
        ], $verticalScope, $countryCode);

        return $this->respondWithArticles($request, $data);
    }

    public function searchTreeRoots(Request $request): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getSearchTreeRoots($verticalScope, $countryCode);

        return $this->respond($request, $data);
    }

    public function searchTreeChildren(Request $request, string $nodeId): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getSearchTreeChildren($nodeId, $verticalScope, $countryCode);

        return $this->respond($request, $data);
    }

    public function searchTreeArticles(Request $request, string $nodeId): JsonResponse
    {
        $verticalScope = $this->resolveVerticalScope();
        $countryCode = $this->resolveCountryCode();
        $data = $this->catalogBrowseService->getSearchTreeArticles($nodeId, $verticalScope, $countryCode);

        return $this->respondWithArticles($request, $data);
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

    private function resolveCountryCode(): ?string
    {
        $company = $this->companyContext->getCompany();

        if ($company === null) {
            return null;
        }

        /** @var string|null $code */
        $code = $company->country_code;

        return $code;
    }

    /**
     * Standard response for non-article endpoints.
     *
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

    /**
     * Respond with articles in paginated format expected by the frontend.
     * Platform returns a flat array; we wrap it in { data: [...], meta: { has_more, cursor, per_page } }.
     *
     * @param  array<int|string, mixed>|null  $data
     */
    private function respondWithArticles(Request $request, ?array $data): JsonResponse
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

        // Platform returns a flat array of articles via PlatformHttpClient->get()
        // which extracts response.json('data'). Normalize to array list.
        $articles = array_is_list($data) ? $data : ($data['articles'] ?? $data['data'] ?? []);

        $companyId = $this->companyContext->requireCompanyId();
        /** @var array<int, array<string, mixed>> $articles */
        $articles = $this->catalogBrowseService->enrichWithInventoryStatus($articles, $companyId);

        return response()->json([
            'data' => [
                'data' => $articles,
                'meta' => [
                    'has_more' => false,
                    'cursor' => null,
                    'per_page' => (int) $request->query('per_page', '20'),
                ],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
