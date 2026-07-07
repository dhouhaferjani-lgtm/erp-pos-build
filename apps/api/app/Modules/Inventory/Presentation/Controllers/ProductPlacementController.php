<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\DTOs\ProductPlacementDto;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use App\Modules\Inventory\Presentation\Requests\AssignProductsRequest;
use App\Modules\Inventory\Presentation\Requests\BulkMovePlacementsRequest;
use App\Modules\Inventory\Presentation\Requests\SetProductPlacementRequest;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Product placements on location nodes. One LIVE placement per
 * (product, location); unassign tombstones (soft-delete) so offline clients
 * converge via the delta endpoint. Placement is product-level in v1 (D9).
 */
class ProductPlacementController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationNodeService $nodeService,
    ) {}

    /**
     * Live placements in a node — paginated {data, meta} + optional ?search=
     * on product name/SKU (FE consumes via api.get, not apiGet).
     */
    public function products(Request $request, string $node): JsonResponse
    {
        $model = $this->resolveNodeForCompany($node);

        $search = $request->query('search');
        $perPage = min(200, max(1, (int) $request->query('per_page', 25)));

        $paginator = $this->nodeService->listNodeProducts(
            $model->id,
            is_string($search) ? $search : null,
            $perPage,
        );

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (ProductPlacement $placement): ProductPlacementDto => ProductPlacementDto::fromModel($placement))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function assignProducts(AssignProductsRequest $request, string $node): JsonResponse
    {
        $model = $this->resolveNodeForCompany($node);
        $company = $this->companyContext->requireCompany();

        /** @var list<string> $productIds */
        $productIds = $request->input('product_ids', []);

        try {
            foreach ($productIds as $productId) {
                $this->nodeService->assignProduct($company->tenant_id, $productId, $model->location_id, $model->id);
            }
        } catch (InvalidArgumentException $e) {
            return $this->locationMismatch($e);
        }

        $paginator = $this->nodeService->listNodeProducts($model->id, null, 200);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (ProductPlacement $placement): ProductPlacementDto => ProductPlacementDto::fromModel($placement))
                ->values()
                ->all(),
        ]);
    }

    /** Unassign = tombstone the product's live placement at the node's location. */
    public function unassignProduct(string $node, string $product): JsonResponse
    {
        $model = $this->resolveNodeForCompany($node);
        $productModel = $this->resolveProductForCompany($product);

        $this->nodeService->unassignProduct($productModel->id, $model->location_id);

        return response()->json(null, 204);
    }

    public function bulkMove(BulkMovePlacementsRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        // The request validated tenant ownership; re-resolve company-scoped.
        $target = $this->resolveNodeForCompany((string) $request->input('node_id'));

        /** @var list<string> $productIds */
        $productIds = $request->input('product_ids', []);

        try {
            $this->nodeService->bulkMove($company->tenant_id, $productIds, $target->id);
        } catch (InvalidArgumentException $e) {
            return $this->locationMismatch($e);
        }

        return response()->json(['data' => ['moved' => count($productIds)]]);
    }

    /** A product's live placements across this company's locations. */
    public function productPlacements(string $product): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $productModel = $this->resolveProductForCompany($product);

        $placements = ProductPlacement::query()
            ->forTenant($company->tenant_id)
            ->where('product_id', $productModel->id)
            ->whereHas('location', function (Builder $query) use ($company): void {
                /** @var Builder<Location> $query */
                $query->where('company_id', $company->id);
            })
            ->with(['product', 'node'])
            ->get();

        return response()->json([
            'data' => $placements
                ->map(fn (ProductPlacement $placement): ProductPlacementDto => ProductPlacementDto::fromModel($placement))
                ->values()
                ->all(),
        ]);
    }

    /** Set (node_id uuid) or clear (node_id null) a product's placement at a location. */
    public function setProductPlacement(SetProductPlacementRequest $request, string $product): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $productModel = $this->resolveProductForCompany($product);

        $locationId = (string) $request->input('location_id');

        /** @var string|null $nodeId */
        $nodeId = $request->input('node_id');

        if ($nodeId === null || $nodeId === '') {
            $this->nodeService->unassignProduct($productModel->id, $locationId);

            return response()->json(['data' => null]);
        }

        try {
            $placement = $this->nodeService->assignProduct($company->tenant_id, $productModel->id, $locationId, $nodeId);
        } catch (InvalidArgumentException $e) {
            return $this->locationMismatch($e);
        }

        return response()->json(['data' => ProductPlacementDto::fromModel($placement)]);
    }

    private function locationMismatch(InvalidArgumentException $e): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'PLACEMENT_LOCATION_MISMATCH',
                'message' => $e->getMessage(),
            ],
        ], 422);
    }

    private function resolveNodeForCompany(string $nodeId): LocationNode
    {
        if (! Str::isUuid($nodeId)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();

        /** @var LocationNode $node */
        $node = LocationNode::query()
            ->forTenant($company->tenant_id)
            ->whereHas('location', function (Builder $query) use ($company): void {
                /** @var Builder<Location> $query */
                $query->where('company_id', $company->id);
            })
            ->findOrFail($nodeId);

        return $node;
    }

    private function resolveProductForCompany(string $productId): Product
    {
        if (! Str::isUuid($productId)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();

        /** @var Product $product */
        $product = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($productId);

        return $product;
    }
}
