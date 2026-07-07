<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Traits\SeedsPlacementFixtures;

final class PlacementWriteTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPlacementFixtures;

    private LocationNodeService $service;

    private Location $location;

    private LocationNode $nodeA;

    private LocationNode $nodeB;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTenantAndCompany();
        $this->service = app(LocationNodeService::class);

        $this->location = $this->seedLocationForCompany();
        $this->nodeA = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Zone A', 'ZA');
        $this->nodeB = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Zone B', 'ZB');
        $this->product = $this->seedProductForCompany('PLW-WIDGET');
    }

    public function test_assign_then_reassign_keeps_one_live_row(): void
    {
        $first = $this->service->assignProduct($this->tenant->id, $this->product->id, $this->location->id, $this->nodeA->id);
        $this->assertSame($this->nodeA->id, $first->node_id);

        $second = $this->service->assignProduct($this->tenant->id, $this->product->id, $this->location->id, $this->nodeB->id);

        $this->assertSame($first->id, $second->id, 'reassign must move the live row, not create a new one');
        $this->assertSame($this->nodeB->id, $second->node_id);
        $this->assertSame(1, ProductPlacement::query()->where('product_id', $this->product->id)->count());
    }

    public function test_unassign_tombstones_live_row(): void
    {
        $this->service->assignProduct($this->tenant->id, $this->product->id, $this->location->id, $this->nodeA->id);

        $this->service->unassignProduct($this->product->id, $this->location->id);

        $this->assertSame(0, ProductPlacement::query()->where('product_id', $this->product->id)->count());
        $this->assertSame(1, ProductPlacement::withTrashed()->where('product_id', $this->product->id)->whereNotNull('deleted_at')->count());
    }

    public function test_assign_after_unassign_creates_new_live_row_without_unique_violation(): void
    {
        $this->service->assignProduct($this->tenant->id, $this->product->id, $this->location->id, $this->nodeA->id);
        $this->service->unassignProduct($this->product->id, $this->location->id);

        $again = $this->service->assignProduct($this->tenant->id, $this->product->id, $this->location->id, $this->nodeB->id);

        $this->assertNull($again->deleted_at);
        $this->assertSame($this->nodeB->id, $again->node_id);
        $this->assertSame(1, ProductPlacement::query()->where('product_id', $this->product->id)->count());
        $this->assertSame(2, ProductPlacement::withTrashed()->where('product_id', $this->product->id)->count());
    }

    public function test_assign_to_node_in_other_location_throws(): void
    {
        $otherLocation = $this->seedLocationForCompany('WH-PLW-02', 'Other Warehouse');

        $this->expectException(InvalidArgumentException::class);

        $this->service->assignProduct($this->tenant->id, $this->product->id, $otherLocation->id, $this->nodeA->id);
    }

    public function test_bulk_move_reassigns_all_products(): void
    {
        $productTwo = $this->seedProductForCompany('PLW-GADGET', 'Placement Gadget');

        $this->service->assignProduct($this->tenant->id, $this->product->id, $this->location->id, $this->nodeA->id);
        $this->service->assignProduct($this->tenant->id, $productTwo->id, $this->location->id, $this->nodeA->id);

        $this->service->bulkMove($this->tenant->id, [$this->product->id, $productTwo->id], $this->nodeB->id);

        $this->assertSame(2, ProductPlacement::query()->inNode($this->nodeB->id)->count());
        $this->assertSame(0, ProductPlacement::query()->inNode($this->nodeA->id)->count());
    }

    public function test_list_node_products_paginates_and_searches(): void
    {
        $productTwo = $this->seedProductForCompany('PLW-GADGET', 'Placement Gadget');

        $this->service->assignProduct($this->tenant->id, $this->product->id, $this->location->id, $this->nodeA->id);
        $this->service->assignProduct($this->tenant->id, $productTwo->id, $this->location->id, $this->nodeA->id);

        $all = $this->service->listNodeProducts($this->nodeA->id, null, 10);
        $this->assertSame(2, $all->total());

        $filtered = $this->service->listNodeProducts($this->nodeA->id, 'Gadget', 10);
        $this->assertSame(1, $filtered->total());
        /** @var ProductPlacement $hit */
        $hit = $filtered->items()[0];
        $this->assertSame($productTwo->id, $hit->product_id);

        // tombstoned placements never appear
        $this->service->unassignProduct($productTwo->id, $this->location->id);
        $this->assertSame(1, $this->service->listNodeProducts($this->nodeA->id, null, 10)->total());
    }
}
