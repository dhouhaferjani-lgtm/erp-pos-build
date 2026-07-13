<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Traits\SeedsPlacementFixtures;

final class LocationNodeMoveTest extends TestCase
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
     * @return array{0: LocationNode, 1: LocationNode, 2: LocationNode, 3: string} [A1, R2, B7, locationId]
     */
    private function buildTree(): array
    {
        $location = $this->seedLocationForCompany();

        $a = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $r = $this->service->createNode($this->tenant->id, $location->id, $a->id, LocationNodeType::Rack, 'Rack 2', 'R2');
        $b = $this->service->createNode($this->tenant->id, $location->id, $r->id, LocationNodeType::Bin, 'Bin 7', 'B7');

        return [$a, $r, $b, $location->id];
    }

    public function test_move_to_root_recomputes_subtree(): void
    {
        [$a, $r, $b] = $this->buildTree();

        $moved = $this->service->moveNode($r, null);

        $this->assertNull($moved->parent_id);
        $this->assertSame('R2', $moved->path);
        $this->assertSame(0, $moved->depth);
        $this->assertSame('R2/B7', $b->refresh()->path);
        $this->assertSame(1, $b->depth);
        // A1 untouched
        $this->assertSame('A1', $a->refresh()->path);
    }

    public function test_move_under_new_parent_recomputes_subtree(): void
    {
        [$a, $r, $b, $locationId] = $this->buildTree();
        $x = $this->service->createNode($this->tenant->id, $locationId, null, LocationNodeType::Zone, 'Zone X', 'X1');

        $moved = $this->service->moveNode($r, $x->id);

        $this->assertSame($x->id, $moved->parent_id);
        $this->assertSame('X1/R2', $moved->path);
        $this->assertSame(1, $moved->depth);
        $this->assertSame('X1/R2/B7', $b->refresh()->path);
        $this->assertSame(2, $b->depth);
    }

    public function test_move_under_own_descendant_throws(): void
    {
        [$a, $r, $b] = $this->buildTree();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('descendant');

        $this->service->moveNode($a, $b->id);
    }

    public function test_move_under_self_throws(): void
    {
        [$a] = $this->buildTree();

        $this->expectException(InvalidArgumentException::class);

        $this->service->moveNode($a, $a->id);
    }

    public function test_move_to_parent_in_other_location_throws(): void
    {
        [$a] = $this->buildTree();
        $otherLocation = $this->seedLocationForCompany('WH-PLC-02', 'Other Warehouse');
        $foreign = $this->service->createNode($this->tenant->id, $otherLocation->id, null, LocationNodeType::Zone, 'Foreign', 'F1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('same location');

        $this->service->moveNode($a, $foreign->id);
    }

    public function test_move_does_not_capture_prefix_sibling(): void
    {
        $location = $this->seedLocationForCompany();

        $a1 = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $a10 = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 10', 'A10');
        $x = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Zone, 'Zone X', 'X1');

        $this->service->moveNode($a1, $x->id);

        $this->assertSame('X1/A1', $a1->refresh()->path);
        $this->assertSame('A10', $a10->refresh()->path);
    }
}
