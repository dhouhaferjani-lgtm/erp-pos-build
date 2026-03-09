<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Enums\Vertical;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\StockLevelData;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Application\DTOs\ProductData;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Presentation\Requests\CreateProductRequest;
use App\Modules\Product\Presentation\Requests\UpdateProductRequest;
use App\Support\Traits\FiltersAndSorts;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProductController extends Controller
{
    use FiltersAndSorts;
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List all products for the current company with sorting, filtering, and aggregates.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();

        // Get sort parameters
        $sortParams = $this->getSortParams(
            $request,
            $this->getAllowedSortColumns(),
            $this->getDefaultSort()['column'],
            $this->getDefaultSort()['direction']
        );

        // Get filter parameters
        $filterConfig = $this->getFilterConfig();
        $filters = $this->getFilterParams($request, $filterConfig);

        // Get per_page parameter (allow up to 2000 for POS systems)
        $perPage = min((int) $request->input('per_page', 25), 2000);

        // Build query with conditional vertical-specific metadata loading
        $with = ['category', 'primaryImage'];
        if ($company->tenant->vertical === Vertical::Parapharmacy) {
            $with[] = 'parapharmacyMetadata.ingredients';
            $with[] = 'parapharmacyMetadata.keyComponents';
            $with[] = 'parapharmacyMetadata.healthClaims';
            $with[] = 'parapharmacyMetadata.certifications';
        }

        if ($company->tenant->vertical->isAutomotive()) {
            $with[] = 'automotiveMetadata.crossReferences';
            $with[] = 'automotiveMetadata.vehicles';
            $with[] = 'automotiveMetadata.criteria';
        }

        $query = Product::query()
            ->where('company_id', $companyId)
            ->with($with);

        // Apply filters
        $this->applyFilters($query, $filters, $filterConfig);

        // Apply sorting
        $this->applySorting($query, $sortParams);

        // Calculate aggregates (on filtered query, before pagination)
        $aggregates = $this->calculateAggregates($query, $this->getAggregateConfig());

        // Paginate
        $paginator = $query->paginate($perPage);

        // Use the trait's formatOffsetPaginatedResponse
        return response()->json(
            $this->formatOffsetPaginatedResponse($paginator, ProductData::class, $aggregates)
        );
    }

    /**
     * Get allowed sort columns for products.
     *
     * @return array<string>
     */
    protected function getAllowedSortColumns(): array
    {
        return ['name', 'sku', 'sale_price', 'type', 'created_at'];
    }

    /**
     * Get default sort configuration.
     *
     * @return array{column: string, direction: string}
     */
    protected function getDefaultSort(): array
    {
        return ['column' => 'name', 'direction' => 'asc'];
    }

    /**
     * Get filter configuration for products.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getFilterConfig(): array
    {
        return [
            'type' => [
                'type' => 'enum',
                'enum' => ProductType::class,
            ],
            'is_physical' => [
                'type' => 'boolean',
                'column' => 'is_physical',
            ],
            'is_active' => [
                'type' => 'boolean',
                'column' => 'is_active',
            ],
            'category_id' => [
                'type' => 'relationship',
                'column' => 'category_id',
            ],
            'price_min' => [
                'type' => 'range',
                'column' => 'sale_price',
                'operator' => '>=',
            ],
            'price_max' => [
                'type' => 'range',
                'column' => 'sale_price',
                'operator' => '<=',
            ],
            'search' => [
                'type' => 'text',
                'columns' => ['name', 'sku', 'barcode'],
            ],
            'has_stock' => [
                'type' => 'computed',
                'callback' => fn ($q) => $q->whereHas('stockLevels', fn ($sq) => $sq->where('quantity', '>', 0)),
            ],
        ];
    }

    /**
     * Get aggregate configuration for products.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getAggregateConfig(): array
    {
        return [
            'total_products' => ['type' => 'count'],
            'total_active' => [
                'type' => 'count',
                'filter' => ['is_active' => true],
            ],
            'average_price' => [
                'type' => 'avg',
                'column' => 'sale_price',
            ],
        ];
    }

    /**
     * Get a single product.
     */
    public function show(Request $request, string $product): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

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

        // Conditionally load vertical-specific metadata
        if ($company->tenant->vertical === Vertical::Parapharmacy) {
            $productModel->load([
                'parapharmacyMetadata.ingredients',
                'parapharmacyMetadata.keyComponents',
                'parapharmacyMetadata.healthClaims',
                'parapharmacyMetadata.certifications',
            ]);
        }

        if ($company->tenant->vertical->isAutomotive()) {
            $productModel->load([
                'automotiveMetadata.crossReferences',
                'automotiveMetadata.vehicles',
                'automotiveMetadata.criteria',
            ]);
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

        // Extract parapharmacy metadata if provided
        $parapharmacyMetadata = null;
        if (array_key_exists('parapharmacy_metadata', $validated)) {
            $parapharmacyMetadata = $validated['parapharmacy_metadata'];
            unset($validated['parapharmacy_metadata']);
        }

        // Extract automotive metadata if provided
        $automotiveMetadata = null;
        if (array_key_exists('automotive_metadata', $validated)) {
            $automotiveMetadata = $validated['automotive_metadata'];
            unset($validated['automotive_metadata']);
        }

        $product = Product::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            ...$validated,
        ]);

        // Create parapharmacy metadata if provided AND tenant is Parapharmacy vertical
        if ($parapharmacyMetadata !== null && is_array($parapharmacyMetadata) && $company->tenant->vertical === Vertical::Parapharmacy) {
            $product->parapharmacyMetadata()->create($parapharmacyMetadata);
        }

        // Create automotive metadata if provided AND tenant is automotive vertical
        if ($automotiveMetadata !== null && is_array($automotiveMetadata) && $company->tenant->vertical->isAutomotive()) {
            $crossReferences = $automotiveMetadata['cross_references'] ?? null;
            $vehicles = $automotiveMetadata['vehicles'] ?? null;
            $criteria = $automotiveMetadata['criteria'] ?? null;
            unset($automotiveMetadata['cross_references'], $automotiveMetadata['vehicles'], $automotiveMetadata['criteria']);

            $metadata = $product->automotiveMetadata()->create($automotiveMetadata);

            if (is_array($crossReferences)) {
                foreach ($crossReferences as $crossRef) {
                    $metadata->crossReferences()->create($crossRef);
                }
            }

            if (is_array($vehicles)) {
                foreach ($vehicles as $vehicle) {
                    $metadata->vehicles()->create($vehicle);
                }
            }

            if (is_array($criteria)) {
                foreach ($criteria as $criterion) {
                    $metadata->criteria()->create($criterion);
                }
            }
        }

        // Load metadata for response if Parapharmacy vertical
        if ($company->tenant->vertical === Vertical::Parapharmacy) {
            $product->load([
                'parapharmacyMetadata.ingredients',
                'parapharmacyMetadata.keyComponents',
                'parapharmacyMetadata.healthClaims',
                'parapharmacyMetadata.certifications',
            ]);
        }

        // Load metadata for response if automotive vertical
        if ($company->tenant->vertical->isAutomotive()) {
            $product->load([
                'automotiveMetadata.crossReferences',
                'automotiveMetadata.vehicles',
                'automotiveMetadata.criteria',
            ]);
        }

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
        $company = $this->companyContext->requireCompany();

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

        // Extract parapharmacy metadata if provided
        $parapharmacyMetadata = null;
        if (array_key_exists('parapharmacy_metadata', $validated)) {
            $parapharmacyMetadata = $validated['parapharmacy_metadata'];
            unset($validated['parapharmacy_metadata']);
        }

        // Extract automotive metadata if provided
        $automotiveMetadata = null;
        if (array_key_exists('automotive_metadata', $validated)) {
            $automotiveMetadata = $validated['automotive_metadata'];
            unset($validated['automotive_metadata']);
        }

        // Update product core fields
        $productModel->update($validated);

        // Update or create parapharmacy metadata if provided AND tenant is Parapharmacy vertical
        if ($parapharmacyMetadata !== null && is_array($parapharmacyMetadata) && $company->tenant->vertical === Vertical::Parapharmacy) {
            $productModel->parapharmacyMetadata()->updateOrCreate(
                ['product_id' => $productModel->id],
                $parapharmacyMetadata
            );
        }

        // Update or create automotive metadata if provided AND tenant is automotive vertical
        if ($automotiveMetadata !== null && is_array($automotiveMetadata) && $company->tenant->vertical->isAutomotive()) {
            $crossReferences = $automotiveMetadata['cross_references'] ?? null;
            $vehicles = $automotiveMetadata['vehicles'] ?? null;
            $criteria = $automotiveMetadata['criteria'] ?? null;
            unset($automotiveMetadata['cross_references'], $automotiveMetadata['vehicles'], $automotiveMetadata['criteria']);

            $metadata = $productModel->automotiveMetadata()->updateOrCreate(
                ['product_id' => $productModel->id],
                $automotiveMetadata
            );

            // Sync cross-references (replace all)
            if (is_array($crossReferences)) {
                $metadata->crossReferences()->delete();
                foreach ($crossReferences as $crossRef) {
                    $metadata->crossReferences()->create($crossRef);
                }
            }

            // Sync vehicles (replace all)
            if (is_array($vehicles)) {
                $metadata->vehicles()->delete();
                foreach ($vehicles as $vehicle) {
                    $metadata->vehicles()->create($vehicle);
                }
            }

            // Sync criteria (replace all)
            if (is_array($criteria)) {
                $metadata->criteria()->delete();
                foreach ($criteria as $criterion) {
                    $metadata->criteria()->create($criterion);
                }
            }
        }

        /** @var Product $freshProduct */
        $freshProduct = $productModel->fresh();

        // Load metadata for response if Parapharmacy vertical
        if ($company->tenant->vertical === Vertical::Parapharmacy) {
            $freshProduct->load([
                'parapharmacyMetadata.ingredients',
                'parapharmacyMetadata.keyComponents',
                'parapharmacyMetadata.healthClaims',
                'parapharmacyMetadata.certifications',
            ]);
        }

        // Load metadata for response if automotive vertical
        if ($company->tenant->vertical->isAutomotive()) {
            $freshProduct->load([
                'automotiveMetadata.crossReferences',
                'automotiveMetadata.vehicles',
                'automotiveMetadata.criteria',
            ]);
        }

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

        // Calculate incoming stock from confirmed purchase orders
        $incomingByLocation = DocumentLine::query()
            ->join('documents', 'document_lines.document_id', '=', 'documents.id')
            ->where('documents.type', DocumentType::PurchaseOrder)
            ->where('documents.status', DocumentStatus::Confirmed)
            ->where('documents.company_id', $companyId)
            ->where('document_lines.product_id', $productModel->id)
            ->whereRaw('document_lines.quantity > COALESCE(document_lines.quantity_received, 0)')
            ->selectRaw('
                document_lines.location_id,
                SUM(document_lines.quantity - COALESCE(document_lines.quantity_received, 0)) as incoming
            ')
            ->groupBy('document_lines.location_id')
            ->get()
            ->keyBy('location_id');

        // Map stock levels with incoming data
        $data = $stockLevels->map(function (StockLevel $level) use ($incomingByLocation) {
            $incoming = $incomingByLocation->get($level->location_id)?->incoming ?? '0.00';

            return StockLevelData::fromModel($level, (string) $incoming);
        });

        // Calculate totals
        $totalQuantity = $stockLevels->sum('quantity');
        $totalReserved = $stockLevels->sum('reserved');
        $totalAvailable = bcsub((string) $totalQuantity, (string) $totalReserved, 2);
        $totalIncoming = $incomingByLocation->sum('incoming');
        $totalProjectedAvailable = bcadd($totalAvailable, (string) $totalIncoming, 2);

        return response()->json([
            'data' => [
                'locations' => $data,
                'totals' => [
                    'quantity' => (string) $totalQuantity,
                    'reserved' => (string) $totalReserved,
                    'available' => $totalAvailable,
                    'incoming' => (string) $totalIncoming,
                    'projected_available' => $totalProjectedAvailable,
                ],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
