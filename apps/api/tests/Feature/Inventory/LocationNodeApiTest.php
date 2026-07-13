<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;
use Tests\Traits\SeedsPlacementFixtures;

final class LocationNodeApiTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;
    use SeedsPlacementFixtures;

    private User $user;

    private Location $location;

    private LocationNodeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTenantAndCompany();
        $this->user = $this->seedPermissionedUser();
        $this->location = $this->seedLocationForCompany();
        $this->service = app(LocationNodeService::class);
    }

    public function test_full_tree_lifecycle_via_api(): void
    {
        // create root node
        $rootResponse = $this->actingAs($this->user)->postJson('/api/v1/inventory/nodes', [
            'location_id' => $this->location->id,
            'node_type' => 'aisle',
            'name' => 'Aisle 1',
            'code' => 'A1',
        ]);
        $rootResponse->assertStatus(201);
        $rootResponse->assertJsonPath('data.path', 'A1');
        $rootResponse->assertJsonPath('data.depth', 0);
        $rootId = (string) $rootResponse->json('data.id');

        // create child
        $childResponse = $this->actingAs($this->user)->postJson('/api/v1/inventory/nodes', [
            'location_id' => $this->location->id,
            'parent_id' => $rootId,
            'node_type' => 'rack',
            'name' => 'Rack 2',
            'code' => 'R2',
        ]);
        $childResponse->assertStatus(201);
        $childResponse->assertJsonPath('data.path', 'A1/R2');
        $childResponse->assertJsonPath('data.parent_id', $rootId);
        $childId = (string) $childResponse->json('data.id');

        // list tree
        $listResponse = $this->actingAs($this->user)->getJson("/api/v1/inventory/locations/{$this->location->id}/nodes");
        $listResponse->assertStatus(200);
        $paths = array_column($listResponse->json('data'), 'path');
        $this->assertEqualsCanonicalizing(['A1', 'A1/R2'], $paths);

        // rename code → subtree path recomputed
        $patchResponse = $this->actingAs($this->user)->patchJson("/api/v1/inventory/nodes/{$rootId}", [
            'code' => 'AX',
        ]);
        $patchResponse->assertStatus(200);
        $patchResponse->assertJsonPath('data.path', 'AX');
        $this->assertSame('AX/R2', LocationNode::query()->findOrFail($childId)->path);

        // move child to root
        $moveResponse = $this->actingAs($this->user)->postJson("/api/v1/inventory/nodes/{$childId}/move", [
            'parent_id' => null,
        ]);
        $moveResponse->assertStatus(200);
        $moveResponse->assertJsonPath('data.path', 'R2');
        $moveResponse->assertJsonPath('data.parent_id', null);

        // soft delete root
        $deleteResponse = $this->actingAs($this->user)->deleteJson("/api/v1/inventory/nodes/{$rootId}");
        $deleteResponse->assertStatus(204);
        $this->assertNotNull(LocationNode::withTrashed()->findOrFail($rootId)->deleted_at);

        // deleted node no longer listed by default...
        $listAfterDelete = $this->actingAs($this->user)->getJson("/api/v1/inventory/locations/{$this->location->id}/nodes");
        $this->assertEqualsCanonicalizing(['R2'], array_column($listAfterDelete->json('data'), 'path'));

        // ...but include_deleted=1 shows the tombstone (sync prune)
        $listWithDeleted = $this->actingAs($this->user)->getJson("/api/v1/inventory/locations/{$this->location->id}/nodes?include_deleted=1");
        $this->assertCount(2, $listWithDeleted->json('data'));

        // restore
        $restoreResponse = $this->actingAs($this->user)->postJson("/api/v1/inventory/nodes/{$rootId}/restore");
        $restoreResponse->assertStatus(200);
        $this->assertNull(LocationNode::withTrashed()->findOrFail($rootId)->deleted_at);
    }

    public function test_delete_with_live_placements_requires_force(): void
    {
        $node = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Zone A', 'ZA');
        $product = $this->seedProductForCompany('API-WIDGET');
        $this->service->assignProduct($this->tenant->id, $product->id, $this->location->id, $node->id);

        $refused = $this->actingAs($this->user)->deleteJson("/api/v1/inventory/nodes/{$node->id}");
        $refused->assertStatus(422);
        $this->assertApiErrorCode($refused, 'NODE_HAS_PLACEMENTS');

        $forced = $this->actingAs($this->user)->deleteJson("/api/v1/inventory/nodes/{$node->id}?force=1");
        $forced->assertStatus(204);
        $this->assertNotNull(ProductPlacement::withTrashed()->where('product_id', $product->id)->firstOrFail()->deleted_at);
    }

    public function test_move_into_own_subtree_returns_422(): void
    {
        $a = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $b = $this->service->createNode($this->tenant->id, $this->location->id, $a->id, LocationNodeType::Bin, 'Bin 7', 'B7');

        $response = $this->actingAs($this->user)->postJson("/api/v1/inventory/nodes/{$a->id}/move", [
            'parent_id' => $b->id,
        ]);

        $response->assertStatus(422);
        $this->assertApiErrorCode($response, 'NODE_MOVE_INVALID');
    }

    public function test_create_with_invalid_code_grammar_returns_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/nodes', [
            'location_id' => $this->location->id,
            'node_type' => 'aisle',
            'name' => 'Bad',
            'code' => 'A/1',
        ]);

        $this->assertApiValidationErrors($response, ['code']);
    }

    public function test_adjust_endpoints_forbidden_without_permission(): void
    {
        $viewer = $this->seedPermissionedUser(['inventory.view']);

        $response = $this->actingAs($viewer)->postJson('/api/v1/inventory/nodes', [
            'location_id' => $this->location->id,
            'node_type' => 'aisle',
            'name' => 'Aisle 1',
            'code' => 'A1',
        ]);
        $response->assertStatus(403);

        $node = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Zone A', 'ZA');
        $this->actingAs($viewer)->deleteJson("/api/v1/inventory/nodes/{$node->id}")->assertStatus(403);

        // view endpoint still allowed
        $this->actingAs($viewer)->getJson("/api/v1/inventory/locations/{$this->location->id}/nodes")->assertStatus(200);
    }

    public function test_cannot_touch_node_of_other_company_location(): void
    {
        $node = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Zone A', 'ZA');

        // rebuild context as a second company in the same tenant
        $companyId = $this->company->id;
        $this->seedTenantAndCompanySecondary();
        $otherUser = $this->seedPermissionedUser();

        $response = $this->actingAs($otherUser)->patchJson("/api/v1/inventory/nodes/{$node->id}", ['name' => 'Steal']);
        $response->assertStatus(404);
    }

    public function test_non_uuid_node_id_returns_404(): void
    {
        $this->actingAs($this->user)->patchJson('/api/v1/inventory/nodes/not-a-uuid', ['name' => 'X'])->assertStatus(404);
        $this->actingAs($this->user)->deleteJson('/api/v1/inventory/nodes/not-a-uuid')->assertStatus(404);
    }

    /**
     * Second company under a NEW tenant with its own context, mirroring the
     * cross-company isolation setup of the old ZoneManagementTest.
     */
    private function seedTenantAndCompanySecondary(): void
    {
        $this->seedTenantAndCompany();
    }
}
