<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;
use Tests\Traits\SeedsPlacementFixtures;

final class PlacementApiTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;
    use SeedsPlacementFixtures;

    private User $user;

    private Location $location;

    private LocationNode $nodeA;

    private LocationNode $nodeB;

    private Product $widget;

    private Product $gadget;

    private Product $gizmo;

    private LocationNodeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTenantAndCompany();
        $this->user = $this->seedPermissionedUser();
        $this->location = $this->seedLocationForCompany();
        $this->service = app(LocationNodeService::class);

        $this->nodeA = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Zone A', 'ZA');
        $this->nodeB = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Zone B', 'ZB');

        $this->widget = $this->seedProductForCompany('PLA-WIDGET', 'Api Widget');
        $this->gadget = $this->seedProductForCompany('PLA-GADGET', 'Api Gadget');
        $this->gizmo = $this->seedProductForCompany('PLA-GIZMO', 'Api Gizmo');
    }

    public function test_assign_list_search_unassign_bulk_move_flow(): void
    {
        // assign 3 products
        $assignResponse = $this->actingAs($this->user)->postJson(
            "/api/v1/inventory/nodes/{$this->nodeA->id}/assign-products",
            ['product_ids' => [$this->widget->id, $this->gadget->id, $this->gizmo->id]],
        );
        $assignResponse->assertStatus(200);

        // paginated list with meta
        $listResponse = $this->actingAs($this->user)->getJson("/api/v1/inventory/nodes/{$this->nodeA->id}/products?per_page=2");
        $listResponse->assertStatus(200);
        $this->assertCount(2, $listResponse->json('data'));
        $this->assertSame(3, $listResponse->json('meta.total'));

        // search
        $searchResponse = $this->actingAs($this->user)->getJson("/api/v1/inventory/nodes/{$this->nodeA->id}/products?search=Gadget");
        $this->assertSame(1, $searchResponse->json('meta.total'));
        $this->assertSame($this->gadget->id, $searchResponse->json('data.0.product_id'));

        // unassign one
        $unassignResponse = $this->actingAs($this->user)->deleteJson(
            "/api/v1/inventory/nodes/{$this->nodeA->id}/products/{$this->gizmo->id}"
        );
        $unassignResponse->assertStatus(204);
        $this->assertSame(0, ProductPlacement::query()->where('product_id', $this->gizmo->id)->count());

        // bulk-move the two remaining to node B
        $bulkResponse = $this->actingAs($this->user)->postJson('/api/v1/inventory/placements/bulk-move', [
            'node_id' => $this->nodeB->id,
            'product_ids' => [$this->widget->id, $this->gadget->id],
        ]);
        $bulkResponse->assertStatus(200);
        $this->assertSame(2, ProductPlacement::query()->inNode($this->nodeB->id)->count());
        $this->assertSame(0, ProductPlacement::query()->inNode($this->nodeA->id)->count());

        // product placements view
        $productResponse = $this->actingAs($this->user)->getJson("/api/v1/inventory/products/{$this->widget->id}/placements");
        $productResponse->assertStatus(200);
        $this->assertCount(1, $productResponse->json('data'));
        $this->assertSame($this->nodeB->id, $productResponse->json('data.0.node_id'));
    }

    public function test_set_product_placement_put_sets_and_clears(): void
    {
        // set
        $setResponse = $this->actingAs($this->user)->putJson("/api/v1/inventory/products/{$this->widget->id}/placements", [
            'location_id' => $this->location->id,
            'node_id' => $this->nodeA->id,
        ]);
        $setResponse->assertStatus(200);
        $this->assertSame(1, ProductPlacement::query()->where('product_id', $this->widget->id)->count());

        // clear
        $clearResponse = $this->actingAs($this->user)->putJson("/api/v1/inventory/products/{$this->widget->id}/placements", [
            'location_id' => $this->location->id,
            'node_id' => null,
        ]);
        $clearResponse->assertStatus(200);
        $this->assertSame(0, ProductPlacement::query()->where('product_id', $this->widget->id)->count());
    }

    public function test_cross_location_assign_returns_422_mismatch(): void
    {
        $otherLocation = $this->seedLocationForCompany('WH-PLA-02', 'Other Warehouse');
        $foreignNode = $this->service->createNode($this->tenant->id, $otherLocation->id, null, LocationNodeType::Zone, 'Foreign', 'F1');

        $response = $this->actingAs($this->user)->putJson("/api/v1/inventory/products/{$this->widget->id}/placements", [
            'location_id' => $this->location->id,
            'node_id' => $foreignNode->id,
        ]);

        $response->assertStatus(422);
        $this->assertApiErrorCode($response, 'PLACEMENT_LOCATION_MISMATCH');
    }

    public function test_adjust_endpoints_forbidden_without_permission(): void
    {
        $viewer = $this->seedPermissionedUser(['inventory.view']);

        $this->actingAs($viewer)->postJson(
            "/api/v1/inventory/nodes/{$this->nodeA->id}/assign-products",
            ['product_ids' => [$this->widget->id]],
        )->assertStatus(403);

        $this->actingAs($viewer)->postJson('/api/v1/inventory/placements/bulk-move', [
            'node_id' => $this->nodeB->id,
            'product_ids' => [$this->widget->id]],
        )->assertStatus(403);

        $this->actingAs($viewer)->getJson("/api/v1/inventory/nodes/{$this->nodeA->id}/products")->assertStatus(200);
    }

    public function test_non_uuid_ids_return_404(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/inventory/nodes/not-a-uuid/products')->assertStatus(404);
        $this->actingAs($this->user)->deleteJson("/api/v1/inventory/nodes/{$this->nodeA->id}/products/not-a-uuid")->assertStatus(404);
    }
}
