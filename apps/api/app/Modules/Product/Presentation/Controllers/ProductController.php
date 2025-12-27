<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\StockLevelData;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Application\DTOs\ProductData;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Presentation\Requests\CreateProductRequest;
use App\Modules\Product\Presentation\Requests\UpdateProductRequest;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProductController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List all products for the current company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $params = $this->getPaginationParams($request);

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
            // Escape LIKE special characters to prevent LIKE pattern injection
            $search = addcslashes($search, '%_\\');
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$search}%"]);
            });
        }

        // Order by name for consistent pagination
        $query->orderBy('name');

        // Use cursor pagination
        $paginator = $query->cursorPaginate($params['per_page'], ['*'], 'cursor', $params['cursor']);

        // Optionally include total stock across all locations
        $includeStock = $request->boolean('include_stock');

        // Transform items with optional stock data
        if ($includeStock) {
            $items = collect($paginator->items())->map(function (Product $product) use ($companyId) {
                $productData = ProductData::fromModel($product);
                $totalStock = StockLevel::where('product_id', $product->id)
                    ->where('company_id', $companyId)
                    ->sum('quantity');

                return array_merge($productData->toArray(), [
                    'total_stock' => (string) $totalStock,
                ]);
            })->all();

            return response()->json([
                'data' => $items,
                'meta' => [
                    'per_page' => $paginator->perPage(),
                    'has_more' => $paginator->hasMorePages(),
                ],
                'links' => [
                    'next' => $paginator->nextCursor()?->encode(),
                    'prev' => $paginator->previousCursor()?->encode(),
                ],
            ]);
        }

        // Use the trait's formatPaginatedResponse for standard response
        return response()->json(
            $this->formatPaginatedResponse($paginator, ProductData::class)
        );
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

    /**
     * Get stock levels for a product across all locations.
     */
    public function stockLevels(Request $request, string $product): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $productModel = Product::where('company_id', $companyId)
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

        $stockLevels = StockLevel::where('product_id', $productModel->id)
            ->where('company_id', $companyId)
            ->with('location')
            ->get();

        $data = $stockLevels->map(
            fn (StockLevel $level) => StockLevelData::fromModel($level)
        );

        $totalQuantity = $stockLevels->sum('quantity');
        $totalReserved = $stockLevels->sum('reserved');
        $totalAvailable = bcsub((string) $totalQuantity, (string) $totalReserved, 2);

        return response()->json([
            'data' => [
                'locations' => $data,
                'totals' => [
                    'quantity' => (string) $totalQuantity,
                    'reserved' => (string) $totalReserved,
                    'available' => $totalAvailable,
                ],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
