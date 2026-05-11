<?php

declare(strict_types=1);

namespace App\Modules\Service\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Service\Application\DTOs\ServiceCategoryData;
use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Service\Presentation\Requests\CreateServiceCategoryRequest;
use App\Modules\Service\Presentation\Requests\UpdateServiceCategoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ServiceCategoryController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ServiceCatalogService $serviceCatalog,
    ) {}

    /**
     * List all service categories for the current company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $filters = [];

        // Filter to root categories only
        if ($request->has('root_only') && $request->boolean('root_only')) {
            $filters['root_only'] = true;
        }

        // Filter by active status
        if ($request->has('active_only') && $request->boolean('active_only')) {
            $filters['active_only'] = true;
        }

        $categories = $this->serviceCatalog->listCategories($companyId, $filters);

        return response()->json([
            'data' => $categories->values()->all(),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Get the category tree for the current company.
     */
    public function tree(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $tree = $this->serviceCatalog->getCategoryTree($companyId);

        return response()->json([
            'data' => $tree,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Get a single service category.
     */
    public function show(Request $request, string $category): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $categoryModel = ServiceCategory::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $category)
            ->withCount('services')
            ->first();

        if (! $categoryModel) {
            return response()->json([
                'error' => [
                    'code' => 'CATEGORY_NOT_FOUND',
                    'message' => 'Service category not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => ServiceCategoryData::fromModel($categoryModel),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new service category.
     */
    public function store(CreateServiceCategoryRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $categoryData = $this->serviceCatalog->createCategory($companyId, $validated);

        return response()->json([
            'data' => $categoryData,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update an existing service category.
     */
    public function update(UpdateServiceCategoryRequest $request, string $category): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $categoryModel = ServiceCategory::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $category)
            ->first();

        if (! $categoryModel) {
            return response()->json([
                'error' => [
                    'code' => 'CATEGORY_NOT_FOUND',
                    'message' => 'Service category not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $categoryData = $this->serviceCatalog->updateCategory($categoryModel->id, $validated);

        return response()->json([
            'data' => $categoryData,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a service category.
     */
    public function destroy(Request $request, string $category): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $categoryModel = ServiceCategory::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $category)
            ->withCount(['services', 'children'])
            ->first();

        if (! $categoryModel) {
            return response()->json([
                'error' => [
                    'code' => 'CATEGORY_NOT_FOUND',
                    'message' => 'Service category not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        // Check if category has services
        if ($categoryModel->services_count > 0) {
            return response()->json([
                'error' => [
                    'code' => 'CATEGORY_HAS_SERVICES',
                    'message' => 'Cannot delete category with existing services',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 422);
        }

        // Check if category has children
        if ($categoryModel->children_count > 0) {
            return response()->json([
                'error' => [
                    'code' => 'CATEGORY_HAS_CHILDREN',
                    'message' => 'Cannot delete category with child categories',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 422);
        }

        $this->serviceCatalog->deleteCategory($categoryModel->id);

        return response()->json(null, 204);
    }
}
