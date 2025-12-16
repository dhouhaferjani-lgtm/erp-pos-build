<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\ProductData;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Presentation\Requests\CreateProductRequest;
use App\Modules\Product\Presentation\Requests\UpdateProductRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProductController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List all products for the current company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $query = Product::query()
            ->where('company_id', $companyId);

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        // Filter by active status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Search by name or SKU (case-insensitive)
        // Use LOWER() for database-agnostic case-insensitive search (works on both PostgreSQL and SQLite)
        if ($request->has('search')) {
            $search = mb_strtolower($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$search}%"]);
            });
        }

        $perPage = (int) $request->input('per_page', 15);
        $products = $query->orderBy('name')->paginate($perPage);

        $data = $products->getCollection()->map(
            fn (Product $product) => ProductData::fromModel($product)
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Get a single product.
     */
    public function show(Request $request, string $product): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $productModel = Product::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $product)
            ->first();

        if (! $productModel) {
            return response()->json([
                'error' => [
                    'code' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Product not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => ProductData::fromModel($productModel),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new product.
     */
    public function store(CreateProductRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $product = Product::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            ...$validated,
        ]);

        return response()->json([
            'data' => ProductData::fromModel($product),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update an existing product.
     */
    public function update(UpdateProductRequest $request, string $product): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $productModel = Product::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $product)
            ->first();

        if (! $productModel) {
            return response()->json([
                'error' => [
                    'code' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Product not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $productModel->update($validated);

        /** @var Product $freshProduct */
        $freshProduct = $productModel->fresh();

        return response()->json([
            'data' => ProductData::fromModel($freshProduct),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a product (soft delete).
     */
    public function destroy(Request $request, string $product): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $productModel = Product::where('company_id', $this->companyContext->requireCompanyId())
            ->where('id', $product)
            ->first();

        if (! $productModel) {
            return response()->json([
                'error' => [
                    'code' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Product not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $productModel->delete();

        return response()->json(null, 204);
    }
}
