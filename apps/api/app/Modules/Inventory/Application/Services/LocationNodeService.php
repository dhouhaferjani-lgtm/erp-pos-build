<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\NodeCode;
use App\Modules\Inventory\Domain\ProductPlacement;
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
