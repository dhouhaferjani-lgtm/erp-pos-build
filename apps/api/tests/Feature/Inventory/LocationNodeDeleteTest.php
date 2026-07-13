<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Traits\SeedsPlacementFixtures;

final class LocationNodeDeleteTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPlacementFixtures;

    private LocationNodeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTenantAndCompany();
        $this->service = app(LocationNodeService::class);
    }

    /**
     * @return array{0: LocationNode, 1: LocationNode, 2: LocationNode, 3: ProductPlacement, 4: string}
     */
    private function buildTreeWithPlacement(): array
    {
        $location = $this->seedLocationForCompany();
        $product = $this->seedProductForCompany('DEL-WIDGET');

        $a = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $r = $this->service->createNode($this->tenant->id, $location->id, $a->id, LocationNodeType::Rack, 'Rack 2', 'R2');
        $b = $this->service->createNode($this->tenant->id, $location->id, $r->id, LocationNodeType::Bin, 'Bin 7', 'B7');

        $placement = ProductPlacement::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'node_id' => $b->id,
        ]);

        return [$a, $r, $b, $placement, $location->id];
    }

    public function test_soft_delete_with_live_placements_refuses_without_force(): void
    {
        [$a] = $this->buildTreeWithPlacement();

        $this->expectException(InvalidArgumentException::class);

        $this->service->softDeleteSubtree($a);
    }

    public function test_soft_delete_with_force_tombstones_nodes_and_placements(): void
    {
        [$a, $r, $b, $placement] = $this->buildTreeWithPlacement();

        $this->service->softDeleteSubtree($a, force: true);

        foreach ([$a, $r, $b] as $node) {
            $fresh = LocationNode::withTrashed()->findOrFail($node->id);
            $this->assertNotNull($fresh->deleted_at, "node {$node->code} should be tombstoned");
        }

        $freshPlacement = ProductPlacement::withTrashed()->findOrFail($placement->id);
        $this->assertNotNull($freshPlacement->deleted_at);

        // live queries exclude the whole subtree
        $this->assertSame(0, LocationNode::query()->count());
        $this->assertSame(0, ProductPlacement::query()->count());
    }

    public function test_soft_delete_without_placements_needs_no_force(): void
    {
        $location = $this->seedLocationForCompany();
        $a = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $r = $this->service->createNode($this->tenant->id, $location->id, $a->id, LocationNodeType::Rack, 'Rack 2', 'R2');

        $this->service->softDeleteSubtree($a);

        $this->assertSame(0, LocationNode::query()->count());
        $this->assertSame(2, LocationNode::withTrashed()->count());
    }

    public function test_soft_delete_does_not_capture_prefix_sibling(): void
    {
        $location = $this->seedLocationForCompany();
        $a1 = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $a10 = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 10', 'A10');

        $this->service->softDeleteSubtree($a1);

        $this->assertNull(LocationNode::withTrashed()->findOrFail($a10->id)->deleted_at);
    }

    public function test_restore_untombstones_node_only(): void
    {
        [$a, $r, $b] = $this->buildTreeWithPlacement();
        $this->service->softDeleteSubtree($a, force: true);

        /** @var LocationNode $trashedA */
        $trashedA = LocationNode::withTrashed()->findOrFail($a->id);
        $this->service->restoreNode($trashedA);

        $this->assertNull(LocationNode::withTrashed()->findOrFail($a->id)->deleted_at);
        // restore is per-node: children stay tombstoned
        $this->assertNotNull(LocationNode::withTrashed()->findOrFail($r->id)->deleted_at);
        $this->assertNotNull(LocationNode::withTrashed()->findOrFail($b->id)->deleted_at);
    }

    public function test_restore_fails_when_code_taken_by_live_sibling(): void
    {
        $location = $this->seedLocationForCompany();
        $a = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $this->service->softDeleteSubtree($a);

        // a new live node reuses the code
        $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1 New', 'A1');

        /** @var LocationNode $trashedA */
        $trashedA = LocationNode::withTrashed()->findOrFail($a->id);

        $this->expectException(InvalidArgumentException::class);

        $this->service->restoreNode($trashedA);
    }
}
