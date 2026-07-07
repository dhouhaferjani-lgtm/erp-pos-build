<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\NodeCode;
use App\Modules\Inventory\Domain\ProductPlacement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Location placement hierarchy: nodes are shelf/aisle/rack/bin LABELS per
 * location for count scoping and product placement — stock quantity never
 * moves to node grain (see docs/superpowers/specs/
 * 2026-07-07-location-placement-hierarchy-design.md).
 *
 * All mutations run in transactions and re-validate location/parent/node
 * consistency inside the transaction (spec §3.4 — the controller is not the
 * only boundary). `path`/`depth` are server-authoritative and recomputed for
 * the whole subtree on create / move / code change.
 */
final class LocationNodeService
{
    public function createNode(
        string $tenantId,
        string $locationId,
        ?string $parentId,
        LocationNodeType $type,
        string $name,
        string $code,
        int $sortOrder = 0,
        bool $isActive = true,
    ): LocationNode {
        if (! NodeCode::isValid($code)) {
            throw new InvalidArgumentException("Invalid node code '{$code}': must match ".NodeCode::PATTERN);
        }

        return DB::transaction(function () use ($tenantId, $locationId, $parentId, $type, $name, $code, $sortOrder, $isActive): LocationNode {
            $parent = null;

            if ($parentId !== null) {
                // Same-location guard is part of the lookup: a parent at a
                // foreign location 404s instead of silently mis-rooting.
                /** @var LocationNode $parent */
                $parent = LocationNode::query()
                    ->where('location_id', $locationId)
                    ->lockForUpdate()
                    ->findOrFail($parentId);
            }

            $path = $parent !== null ? $parent->path.'/'.$code : $code;
            $depth = $parent !== null ? $parent->depth + 1 : 0;

            return LocationNode::create([
                'tenant_id' => $tenantId,
                'location_id' => $locationId,
                'parent_id' => $parentId,
                'node_type' => $type->value,
                'name' => $name,
                'code' => $code,
                'path' => $path,
                'depth' => $depth,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateNode(LocationNode $node, array $attributes): LocationNode
    {
        return DB::transaction(function () use ($node, $attributes): LocationNode {
            $node->fill($attributes);

            $codeChanged = $node->isDirty('code');

            if ($codeChanged && ! NodeCode::isValid($node->code)) {
                throw new InvalidArgumentException("Invalid node code '{$node->code}': must match ".NodeCode::PATTERN);
            }

            $node->save();

            if ($codeChanged) {
                $this->recomputeSubtreePath($node);
            }

            return $node->refresh();
        });
    }

    /**
     * Atomically reparent a node (and its whole subtree). Locks the node and
     * the new parent, rejects a cross-location parent and a cycle (new parent
     * inside the moving subtree), then updates parent_id and recomputes
     * path/depth for the subtree.
     */
    public function moveNode(LocationNode $node, ?string $newParentId): LocationNode
    {
        return DB::transaction(function () use ($node, $newParentId): LocationNode {
            /** @var LocationNode $node */
            $node = LocationNode::query()->lockForUpdate()->findOrFail($node->id);

            if ($newParentId !== null) {
                /** @var LocationNode $newParent */
                $newParent = LocationNode::query()->lockForUpdate()->findOrFail($newParentId);

                if ($newParent->location_id !== $node->location_id) {
                    throw new InvalidArgumentException('Parent must be in the same location.');
                }

                // Cycle guard: the new parent is the node itself or inside
                // its subtree ('/'-anchored so A1 never captures A10).
                if ($newParent->id === $node->id
                    || $newParent->path === $node->path
                    || str_starts_with($newParent->path, $node->path.'/')) {
                    throw new InvalidArgumentException('Cannot move a node under itself or its own descendant.');
                }
            }

            $node->parent_id = $newParentId;
            $node->save();

            $this->recomputeSubtreePath($node);

            return $node->refresh();
        });
    }

    /**
     * Tombstone a node, its whole subtree, and their live placements in ONE
     * transaction — explicitly, never via FK cascade (cascade does not fire
     * on soft-delete; spec D10). Refuses when live placements exist under the
     * subtree unless $force.
     */
    public function softDeleteSubtree(LocationNode $node, bool $force = false): void
    {
        DB::transaction(function () use ($node, $force): void {
            /** @var LocationNode $node */
            $node = LocationNode::query()->lockForUpdate()->findOrFail($node->id);

            /** @var list<string> $subtreeIds */
            $subtreeIds = LocationNode::query()
                ->atLocation($node->location_id)
                ->subtreeOf($node->path)
                ->pluck('id')
                ->all();

            $livePlacements = ProductPlacement::query()
                ->whereIn('node_id', $subtreeIds)
                ->count();

            if ($livePlacements > 0 && ! $force) {
                throw new InvalidArgumentException(
                    "Subtree of node {$node->id} has {$livePlacements} live product placement(s); pass force to tombstone them too."
                );
            }

            $now = now();

            LocationNode::query()->whereIn('id', $subtreeIds)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);

            ProductPlacement::query()->whereIn('node_id', $subtreeIds)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Un-tombstone a single node (children stay deleted — restore is
     * per-node). Fails when the code has been reused by a live sibling at the
     * same location (the partial unique only covers live rows).
     */
    public function restoreNode(LocationNode $node): void
    {
        DB::transaction(function () use ($node): void {
            $codeTaken = LocationNode::query()
                ->atLocation($node->location_id)
                ->where('code', $node->code)
                ->whereKeyNot($node->id)
                ->exists();

            if ($codeTaken) {
                throw new InvalidArgumentException(
                    "Cannot restore node {$node->id}: code '{$node->code}' is used by a live node at this location."
                );
            }

            $node->restore();
        });
    }

    /**
     * Upsert a product's live placement at a location (write algorithm of
     * spec §3.2): lock the live (product, location) row; if present move it
     * (UPDATE node_id), else INSERT a fresh live row — the partial unique
     * (product_id, location_id) WHERE deleted_at IS NULL permits any number
     * of tombstones alongside the single live row.
     *
     * @throws InvalidArgumentException when the node is not in $locationId
     */
    public function assignProduct(string $tenantId, string $productId, string $locationId, string $nodeId): ProductPlacement
    {
        return DB::transaction(function () use ($tenantId, $productId, $locationId, $nodeId): ProductPlacement {
            /** @var LocationNode $node */
            $node = LocationNode::query()->findOrFail($nodeId);

            if ($node->location_id !== $locationId) {
                throw new InvalidArgumentException("Node {$nodeId} is not in location {$locationId}.");
            }

            /** @var ProductPlacement|null $live */
            $live = ProductPlacement::query()
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            if ($live !== null) {
                $live->update(['node_id' => $nodeId]);

                return $live->refresh();
            }

            return ProductPlacement::create([
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'location_id' => $locationId,
                'node_id' => $nodeId,
            ]);
        });
    }

    /**
     * Tombstone the product's live placement at a location (spec §3.2).
     * No-op when there is no live placement.
     */
    public function unassignProduct(string $productId, string $locationId): void
    {
        $now = now();

        ProductPlacement::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * Move a batch of products onto one target node (same-location placements
     * only — each write revalidates through assignProduct).
     *
     * @param  list<string>  $productIds
     */
    public function bulkMove(string $tenantId, array $productIds, string $targetNodeId): void
    {
        /** @var LocationNode $target */
        $target = LocationNode::query()->findOrFail($targetNodeId);

        foreach ($productIds as $productId) {
            $this->assignProduct($tenantId, $productId, $target->location_id, $targetNodeId);
        }
    }

    /**
     * Live placements in a node, paginated, optionally filtered by product
     * name/SKU.
     *
     * @return LengthAwarePaginator<int, ProductPlacement>
     */
    public function listNodeProducts(string $nodeId, ?string $search, int $perPage): LengthAwarePaginator
    {
        return ProductPlacement::query()
            ->inNode($nodeId)
            ->with('product')
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                $like = mb_strtolower($search);
                $query->whereHas('product', function ($product) use ($like): void {
                    $product->whereRaw('LOWER(name) LIKE ?', ['%'.$like.'%'])
                        ->orWhereRaw('LOWER(sku) LIKE ?', ['%'.$like.'%']);
                });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Recompute `path`/`depth` for $node (from its parent + code) and rewrite
     * every descendant in a single prefix-replacement UPDATE. Descendant depth
     * is recomputed from the new path's '/'-segment count. The LIKE prefix is
     * safe unescaped: codes ban '%'/'_' (NodeCode grammar, D11).
     */
    public function recomputeSubtreePath(LocationNode $node): void
    {
        $parent = $node->parent_id !== null ? LocationNode::query()->find($node->parent_id) : null;
        $newPath = $parent !== null ? $parent->path.'/'.$node->code : $node->code;

        /** @var string $oldPath */
        $oldPath = $node->getOriginal('path') ?? $node->path;

        if ($oldPath === $newPath) {
            return;
        }

        $node->forceFill([
            'path' => $newPath,
            'depth' => $parent !== null ? $parent->depth + 1 : 0,
        ])->save();

        // Whole subtree, single UPDATE: swap the old prefix for the new one,
        // recompute depth as the '/'-count of the rewritten path.
        $substrStart = strlen($oldPath) + 1;
        DB::update(
            "UPDATE location_nodes
                SET path = ? || substr(path, ?),
                    depth = length(? || substr(path, ?)) - length(replace(? || substr(path, ?), '/', ''))
              WHERE location_id = ? AND path LIKE ?",
            [
                $newPath, $substrStart,
                $newPath, $substrStart,
                $newPath, $substrStart,
                $node->location_id, $oldPath.'/%',
            ],
        );
    }
}
