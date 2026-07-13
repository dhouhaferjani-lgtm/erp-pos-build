<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\NodeCode;
use RuntimeException;

/**
 * One planner for product-import dry runs and commits. The preview persists a
 * service-level plan on each staging row; commit consumes that exact plan and
 * performs every node/placement mutation through LocationNodeService.
 */
final class ProductPlacementImportService
{
    public function __construct(
        private readonly LocationNodeService $locationNodeService,
    ) {}

    public function prepareJob(ImportJob $job, string $companyId): void
    {
        if ($job->type !== ImportType::Products) {
            return;
        }

        $job->rows()->orderBy('row_number')->each(function (ImportRow $row) use ($job, $companyId): void {
            $data = $row->data;
            $errors = $row->errors ?? [];
            unset($errors['placement_path'], $data['_placement_plan']);

            try {
                $plan = $this->buildPlan($job, $data, $companyId);
                if ($plan !== null) {
                    $data['_placement_plan'] = $plan;
                }
            } catch (RuntimeException $exception) {
                $errors['placement_path'] = [$exception->getMessage()];
            }

            $row->update([
                'data' => $data,
                'errors' => $errors === [] ? null : $errors,
                'is_valid' => $errors === [],
            ]);
        });

        $job->update([
            'successful_rows' => $job->rows()->where('is_valid', true)->count(),
            'failed_rows' => $job->rows()->where('is_valid', false)->count(),
        ]);
    }

    /**
     * @return array{max_depth: int, nodes_to_create: list<array<string, mixed>>, placements_to_set: list<array<string, mixed>>}
     */
    public function preview(ImportJob $job): array
    {
        $nodes = [];
        $placements = [];
        $maxDepth = 0;

        foreach ($job->rows()->orderBy('row_number')->get() as $row) {
            $rawPath = trim((string) ($row->data['placement_path'] ?? ''));
            if ($rawPath !== '') {
                $maxDepth = max($maxDepth, count($this->pathSegments($rawPath)));
            }

            $plan = $row->data['_placement_plan'] ?? null;
            if (! is_array($plan)) {
                continue;
            }

            foreach ($plan['nodes_to_create'] ?? [] as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $key = (string) ($plan['location_id'] ?? '').'|'.(string) ($node['path'] ?? '');
                $nodes[$key] = $node;
            }

            $placements[] = [
                'row_number' => $row->row_number,
                'location_code' => $plan['location_code'] ?? '',
                'path' => $plan['path'] ?? '',
                'node_id' => $plan['final_node_id'] ?? null,
            ];
        }

        return [
            'max_depth' => $maxDepth,
            'nodes_to_create' => array_values($nodes),
            'placements_to_set' => $placements,
        ];
    }

    public function commitRow(ImportJob $job, ImportRow $row, string $productId): void
    {
        if (trim((string) ($row->data['placement_path'] ?? '')) === '') {
            return;
        }

        $plan = $row->data['_placement_plan'] ?? null;
        if (! is_array($plan)) {
            throw new RuntimeException('Placement dry-run plan is missing. Validate the import again before committing.');
        }

        $locationId = (string) $plan['location_id'];
        $parentId = null;

        foreach ($plan['segments'] as $segment) {
            if (! is_array($segment)) {
                throw new RuntimeException('Invalid persisted placement plan.');
            }

            $existingId = $segment['existing_node_id'] ?? null;
            $node = is_string($existingId)
                ? LocationNode::query()->atLocation($locationId)->find($existingId)
                : null;

            if ($node === null) {
                $node = LocationNode::query()
                    ->atLocation($locationId)
                    ->where('code', (string) $segment['code'])
                    ->first();
            }

            if ($node === null) {
                $type = LocationNodeType::tryFrom((string) $segment['node_type']);
                if ($type === null) {
                    throw new RuntimeException('Invalid node type in persisted placement plan.');
                }
                $node = $this->locationNodeService->createNode(
                    $job->tenant_id,
                    $locationId,
                    $parentId,
                    $type,
                    (string) $segment['name'],
                    (string) $segment['code'],
                    ((int) $segment['depth'] + 1) * 10,
                );
            }

            if ($node->parent_id !== $parentId || $node->path !== (string) $segment['path']) {
                throw new RuntimeException("Placement path changed after dry-run at '{$segment['path']}'. Run preview again.");
            }

            $parentId = $node->id;
        }

        if ($parentId === null) {
            throw new RuntimeException('Placement plan resolved no final node.');
        }

        $this->locationNodeService->assignProduct($job->tenant_id, $productId, $locationId, $parentId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function buildPlan(ImportJob $job, array $data, string $companyId): ?array
    {
        $rawPath = trim((string) ($data['placement_path'] ?? ''));
        if ($rawPath === '') {
            return null;
        }

        $locationCode = trim((string) ($data['location_code'] ?? ''));
        if ($locationCode === '') {
            throw new RuntimeException('location_code is required when placement_path is provided.');
        }

        /** @var Location|null $location */
        $location = Location::query()
            ->where('company_id', $companyId)
            ->where('code', $locationCode)
            ->first();
        if ($location === null) {
            throw new RuntimeException("Unknown location_code '{$locationCode}' for placement_path.");
        }

        $segments = $this->pathSegments($rawPath);
        if ($segments === []) {
            throw new RuntimeException('placement_path must contain at least one segment.');
        }

        $mode = $job->options['placement_mode'] ?? 'strict';
        $nodeTypes = $job->options['placement_node_types'] ?? [];
        $parentId = null;
        $pathParts = [];
        $plannedSegments = [];
        $nodesToCreate = [];
        $missing = false;

        foreach ($segments as $depth => $segment) {
            $node = null;
            if (! $missing) {
                $scope = LocationNode::query()
                    ->forTenant($job->tenant_id)
                    ->atLocation($location->id)
                    ->where('parent_id', $parentId);

                $node = (clone $scope)->where('code', $segment)->first();
                if ($node === null) {
                    $nameMatches = (clone $scope)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($segment)])
                        ->orderBy('id')
                        ->get();
                    if ($nameMatches->count() > 1) {
                        throw new RuntimeException("Ambiguous placement segment '{$segment}' at depth ".($depth + 1).'. Use the node code.');
                    }
                    $node = $nameMatches->first();
                }
            }

            if ($node !== null) {
                $pathParts[] = $node->code;
                $plannedSegments[] = [
                    'depth' => $depth,
                    'existing_node_id' => $node->id,
                    'node_type' => $node->node_type->value,
                    'name' => $node->name,
                    'code' => $node->code,
                    'path' => implode('/', $pathParts),
                ];
                $parentId = $node->id;

                continue;
            }

            if ($mode !== 'auto_create') {
                throw new RuntimeException("Missing placement segment '{$segment}' at depth ".($depth + 1).' in strict mode.');
            }
            if (! NodeCode::isValid($segment)) {
                throw new RuntimeException("Auto-created placement segment '{$segment}' must be a valid node code.");
            }

            $conflictingCode = LocationNode::query()
                ->forTenant($job->tenant_id)
                ->atLocation($location->id)
                ->where('code', $segment)
                ->first();
            if ($conflictingCode !== null) {
                throw new RuntimeException(
                    "Placement code '{$segment}' already exists at '{$conflictingCode->path}' and cannot be auto-created at this depth."
                );
            }

            $typeValue = is_array($nodeTypes) ? ($nodeTypes[$depth] ?? null) : null;
            $type = is_string($typeValue) ? LocationNodeType::tryFrom($typeValue) : null;
            if ($type === null) {
                throw new RuntimeException('Choose a node type for placement depth '.($depth + 1).'.');
            }

            $missing = true;
            $pathParts[] = $segment;
            $planned = [
                'depth' => $depth,
                'existing_node_id' => null,
                'node_type' => $type->value,
                'name' => $segment,
                'code' => $segment,
                'path' => implode('/', $pathParts),
            ];
            $plannedSegments[] = $planned;
            $nodesToCreate[] = $planned;
            $parentId = null;
        }

        return [
            'location_id' => $location->id,
            'location_code' => $locationCode,
            'path' => implode('/', $pathParts),
            'final_node_id' => $missing ? null : $parentId,
            'segments' => $plannedSegments,
            'nodes_to_create' => $nodesToCreate,
        ];
    }

    /**
     * @return list<string>
     */
    private function pathSegments(string $path): array
    {
        return array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), explode('>', $path)),
            static fn (string $segment): bool => $segment !== '',
        ));
    }
}
