<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Traits\SeedsPlacementFixtures;

final class LocationNodeServiceCreateTest extends TestCase
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

    public function test_create_computes_path_and_depth(): void
    {
        $location = $this->seedLocationForCompany();

        $a = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $r = $this->service->createNode($this->tenant->id, $location->id, $a->id, LocationNodeType::Rack, 'Rack 2', 'R2');
        $b = $this->service->createNode($this->tenant->id, $location->id, $r->id, LocationNodeType::Bin, 'Bin 7', 'B7');

        $this->assertSame('A1', $a->path);
        $this->assertSame(0, $a->depth);
        $this->assertSame('A1/R2', $r->path);
        $this->assertSame(1, $r->depth);
        $this->assertSame('A1/R2/B7', $b->path);
        $this->assertSame(2, $b->depth);
        $this->assertSame(LocationNodeType::Rack, $r->node_type);
    }

    public function test_create_rejects_parent_from_other_location(): void
    {
        $location = $this->seedLocationForCompany();
        $other = $this->seedLocationForCompany('WH-PLC-02', 'Other Warehouse');

        $a = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');

        $this->expectException(ModelNotFoundException::class);
        $this->service->createNode($this->tenant->id, $other->id, $a->id, LocationNodeType::Rack, 'Rack 2', 'R2');
    }

    public function test_code_change_recomputes_subtree(): void
    {
        $location = $this->seedLocationForCompany();

        $a = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $r = $this->service->createNode($this->tenant->id, $location->id, $a->id, LocationNodeType::Rack, 'Rack 2', 'R2');
        $b = $this->service->createNode($this->tenant->id, $location->id, $r->id, LocationNodeType::Bin, 'Bin 7', 'B7');

        $updated = $this->service->updateNode($a, ['code' => 'AX']);

        $this->assertSame('AX', $updated->path);
        $this->assertSame(0, $updated->depth);
        $this->assertSame('AX/R2', $r->refresh()->path);
        $this->assertSame(1, $r->depth);
        $this->assertSame('AX/R2/B7', $b->refresh()->path);
        $this->assertSame(2, $b->depth);
    }

    public function test_code_change_does_not_touch_prefix_sibling(): void
    {
        $location = $this->seedLocationForCompany();

        // A1 and A10: bare-prefix cousins — renaming A1 must NOT rewrite A10's subtree.
        $a1 = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $a10 = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 10', 'A10');
        $r = $this->service->createNode($this->tenant->id, $location->id, $a10->id, LocationNodeType::Rack, 'Rack 1', 'R1');

        $this->service->updateNode($a1, ['code' => 'AX']);

        $this->assertSame('A10', $a10->refresh()->path);
        $this->assertSame('A10/R1', $r->refresh()->path);
    }

    public function test_create_rejects_invalid_code_grammar(): void
    {
        $location = $this->seedLocationForCompany();

        $this->expectException(InvalidArgumentException::class);
        $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Bad', 'A/1');
    }

    public function test_update_name_only_keeps_path(): void
    {
        $location = $this->seedLocationForCompany();
        $a = $this->service->createNode($this->tenant->id, $location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');

        $updated = $this->service->updateNode($a, ['name' => 'Renamed Aisle']);

        $this->assertSame('Renamed Aisle', $updated->name);
        $this->assertSame('A1', $updated->path);
        $this->assertInstanceOf(LocationNode::class, $updated);
    }
}
