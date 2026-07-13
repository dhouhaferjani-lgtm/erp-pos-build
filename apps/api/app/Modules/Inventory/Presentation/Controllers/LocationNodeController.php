<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\DTOs\LocationNodeDto;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Presentation\Requests\CreateNodeRequest;
use App\Modules\Inventory\Presentation\Requests\MoveNodeRequest;
use App\Modules\Inventory\Presentation\Requests\UpdateNodeRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Location placement hierarchy tree CRUD. Nodes are labels only — stock
 * quantity stays at (product, location[, variant]) grain. Deletes tombstone
 * the subtree (+ placements with ?force=1); restore un-tombstones one node.
 */
class LocationNodeController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationNodeService $nodeService,
    ) {}

    /**
     * Full node tree for a location — flat list carrying parent_id/path.
     * Excludes tombstones unless ?include_deleted=1 (offline-sync prune).
     */
    public function index(Request $request, string $location): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($location)) {
            abort(404);
        }

        // 404s if the location doesn't belong to the current company.
        Location::query()->where('company_id', $company->id)->findOrFail($location);

        $query = LocationNode::query()
            ->forTenant($company->tenant_id)
            ->atLocation($location)
            ->withCount('productPlacements')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        return response()->json([
            'data' => $query->get()
                ->map(fn (LocationNode $node): LocationNodeDto => LocationNodeDto::fromModel($node))
                ->all(),
        ]);
    }

    public function store(CreateNodeRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        /** @var string|null $parentId */
        $parentId = $request->input('parent_id');

        $node = $this->nodeService->createNode(
            tenantId: $company->tenant_id,
            locationId: (string) $request->input('location_id'),
            parentId: $parentId !== null && $parentId !== '' ? (string) $parentId : null,
            type: $request->enum('node_type', LocationNodeType::class) ?? LocationNodeType::Zone,
            name: (string) $request->input('name'),
            code: (string) $request->input('code'),
            sortOrder: (int) $request->input('sort_order', 0),
            isActive: (bool) $request->input('is_active', true),
        );

        return response()->json(['data' => LocationNodeDto::fromModel($node)], 201);
    }

    public function update(UpdateNodeRequest $request, string $node): JsonResponse
    {
        $model = $this->resolveNodeForCompany($node);

        /** @var array<string, mixed> $attributes */
        $attributes = array_intersect_key(
            $request->validated(),
            array_flip(['name', 'code', 'node_type', 'sort_order', 'is_active']),
        );

        $updated = $this->nodeService->updateNode($model, $attributes);

        return response()->json(['data' => LocationNodeDto::fromModel($updated)]);
    }

    public function move(MoveNodeRequest $request, string $node): JsonResponse
    {
        $model = $this->resolveNodeForCompany($node);

        /** @var string|null $parentId */
        $parentId = $request->input('parent_id');

        try {
            $moved = $this->nodeService->moveNode($model, $parentId !== null && $parentId !== '' ? (string) $parentId : null);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'NODE_MOVE_INVALID',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json(['data' => LocationNodeDto::fromModel($moved)]);
    }

    public function destroy(Request $request, string $node): JsonResponse
    {
        $model = $this->resolveNodeForCompany($node);

        try {
            $this->nodeService->softDeleteSubtree($model, force: $request->boolean('force'));
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'NODE_HAS_PLACEMENTS',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json(null, 204);
    }

    public function restore(string $node): JsonResponse
    {
        $model = $this->resolveNodeForCompany($node, withTrashed: true);

        try {
            $this->nodeService->restoreNode($model);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'NODE_CODE_TAKEN',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json(['data' => LocationNodeDto::fromModel($model->refresh())]);
    }

    private function resolveNodeForCompany(string $nodeId, bool $withTrashed = false): LocationNode
    {
        if (! Str::isUuid($nodeId)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();

        $query = LocationNode::query()
            ->forTenant($company->tenant_id)
            ->whereHas('location', function (Builder $query) use ($company): void {
                /** @var Builder<Location> $query */
                $query->where('company_id', $company->id);
            });

        if ($withTrashed) {
            $query->withTrashed();
        }

        /** @var LocationNode $node */
        $node = $query->findOrFail($nodeId);

        return $node;
    }
}
