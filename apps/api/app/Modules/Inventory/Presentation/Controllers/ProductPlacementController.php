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
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
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

    /**
     * Delta sync for mobile (spec §4.4 — DEFINED contract): server-issued
     * sync_high_watermark + (updated_at, id) tuple cursor; rows include
     * tombstones (deleted_at). The client persists the cursor only after the
     * final page (next_cursor = null) and carries sync_high_watermark across
     * pages of one run. Timestamps are ISO 8601 on the wire; comparisons bind
     * Carbon instances so each driver formats them natively (never a raw ISO
     * string against a TEXT column — repo rule 20).
     */
    public function delta(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $locationId = (string) $request->query('location_id', '');
        abort_unless(Str::isUuid($locationId), 422, 'A valid location_id query parameter is required.');

        // 404s if the location doesn't belong to the current company.
        Location::query()->where('company_id', $company->id)->findOrFail($locationId);

        $limit = min(1000, max(1, (int) $request->query('limit', 500)));

        // Malformed sync input must 422, never 500 (review IMPORTANT-3): a
        // poison cursor persisted on a device must not become a permanent
        // sync wall, and a non-uuid cursor id would 22P02 on the PG uuid
        // column.
        $hwmParam = $request->query('sync_high_watermark');
        $hwm = now();

        if (is_string($hwmParam) && $hwmParam !== '') {
            $hwm = $this->parseWireTimestamp($hwmParam, 'sync_high_watermark');
        }

        $query = ProductPlacement::query()
            ->withTrashed()
            ->where('tenant_id', $company->tenant_id)
            ->where('location_id', $locationId)
            ->where('updated_at', '<=', $hwm)
            ->orderBy('updated_at')
            ->orderBy('id');

        $cursor = $request->query('cursor');

        if (is_string($cursor) && $cursor !== '') {
            abort_unless(str_contains($cursor, '|'), 422, 'cursor must be "<iso8601>|<uuid>".');

            [$cursorTs, $cursorId] = explode('|', $cursor, 2);
            abort_unless(Str::isUuid($cursorId), 422, 'cursor id must be a uuid.');

            $cursorTime = $this->parseWireTimestamp($cursorTs, 'cursor');
            $query->where(function ($outer) use ($cursorTime, $cursorId): void {
                $outer->where('updated_at', '>', $cursorTime)
                    ->orWhere(function ($inner) use ($cursorTime, $cursorId): void {
                        $inner->where('updated_at', $cursorTime)->where('id', '>', $cursorId);
                    });
            });
        }

        $rows = $query->with('product')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        /** @var ProductPlacement|null $last */
        $last = $page->last();
        $nextCursor = $hasMore && $last !== null
            ? ['updated_at' => $last->updated_at?->toIso8601String(), 'id' => $last->id]
            : null;

        return response()->json([
            'data' => $page
                ->map(fn (ProductPlacement $placement): ProductPlacementDto => ProductPlacementDto::fromModel($placement))
                ->values()
                ->all(),
            'next_cursor' => $nextCursor,
            'sync_high_watermark' => $hwm->toIso8601String(),
        ]);
    }

    /** Parse a wire timestamp defensively: garbage → 422, never a 500. */
    private function parseWireTimestamp(string $value, string $field): Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            abort(422, "Invalid {$field} timestamp.");
        }
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
