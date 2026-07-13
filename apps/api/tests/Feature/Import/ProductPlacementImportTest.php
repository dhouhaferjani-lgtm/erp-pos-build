<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Tests\Traits\SeedsPlacementFixtures;

final class ProductPlacementImportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPlacementFixtures;

    private User $user;

    private Location $location;

    private LocationNodeService $nodes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTenantAndCompany();
        $this->user = $this->seedPermissionedUser(['imports.manage']);
        $this->location = $this->seedLocationForCompany('MAIN', 'Main warehouse');
        $this->nodes = app(LocationNodeService::class);
        Storage::fake('local');
    }

    public function test_strict_preview_and_commit_set_an_existing_code_first_path(): void
    {
        $aisle = $this->nodes->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $rack = $this->nodes->createNode($this->tenant->id, $this->location->id, $aisle->id, LocationNodeType::Rack, 'Rack 2', 'R2');
        $bin = $this->nodes->createNode($this->tenant->id, $this->location->id, $rack->id, LocationNodeType::Bin, 'Bin 7', 'B7');

        $upload = $this->upload("name,sku,location_code,placement_path\nOil Filter,IMP-PLC-1,MAIN,A1 > R2 > B7");
        $upload->assertCreated();
        $jobId = (string) $upload->json('data.id');

        $preview = $this->actingAs($this->user)->getJson("/api/v1/imports/{$jobId}/preview");
        $preview->assertOk();
        $preview->assertJsonPath('data.placement.nodes_to_create', []);
        $preview->assertJsonPath('data.placement.placements_to_set.0.path', 'A1/R2/B7');
        $this->assertNotContains('_placement_plan', $preview->json('data.headers'));
        $this->assertArrayNotHasKey('_placement_plan', $preview->json('data.rows.0.data'));

        $this->actingAs($this->user)->postJson("/api/v1/imports/{$jobId}/execute")->assertOk();

        $product = Product::query()->where('sku', 'IMP-PLC-1')->firstOrFail();
        $this->assertDatabaseHas('product_placements', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'node_id' => $bin->id,
            'deleted_at' => null,
        ]);
    }

    public function test_auto_create_preview_is_write_free_and_commit_uses_the_previewed_plan(): void
    {
        $this->nodes->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');

        $upload = $this->upload(
            "name,sku,location_code,placement_path\nBrake Pad,IMP-PLC-2,MAIN,A1 > R2 > B7",
            [
                'placement_mode' => 'auto_create',
                'placement_node_types' => ['aisle', 'rack', 'bin'],
            ],
        );
        $upload->assertCreated();
        $jobId = (string) $upload->json('data.id');

        $preview = $this->actingAs($this->user)->getJson("/api/v1/imports/{$jobId}/preview");
        $preview->assertOk();
        $preview->assertJsonPath('data.placement.max_depth', 3);
        $preview->assertJsonCount(2, 'data.placement.nodes_to_create');
        $preview->assertJsonPath('data.placement.nodes_to_create.0.path', 'A1/R2');
        $preview->assertJsonPath('data.placement.nodes_to_create.1.path', 'A1/R2/B7');
        $this->assertSame(1, LocationNode::query()->count(), 'Dry-run preview must not create nodes.');
        $this->assertSame(0, ProductPlacement::query()->count(), 'Dry-run preview must not create placements.');

        $this->actingAs($this->user)->postJson("/api/v1/imports/{$jobId}/execute")->assertOk();

        $this->assertSame(3, LocationNode::query()->count());
        $this->assertDatabaseHas('location_nodes', ['location_id' => $this->location->id, 'path' => 'A1/R2/B7']);
        $this->assertDatabaseHas('product_placements', ['location_id' => $this->location->id]);
    }

    public function test_name_segments_fail_deterministically_when_ambiguous(): void
    {
        $this->nodes->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Shared zone', 'Z1');
        $this->nodes->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Shared zone', 'Z2');

        $upload = $this->upload("name,sku,location_code,placement_path\nAmbiguous,IMP-PLC-3,MAIN,Shared zone");
        $upload->assertCreated();

        $preview = $this->actingAs($this->user)->getJson('/api/v1/imports/'.$upload->json('data.id').'/preview');
        $preview->assertOk();
        $preview->assertJsonPath('data.rows.0.is_valid', false);
        $this->assertStringContainsString(
            "Ambiguous placement segment 'Shared zone' at depth 1",
            (string) $preview->json('data.rows.0.errors.placement_path.0'),
        );
    }

    public function test_auto_create_rejects_a_code_that_exists_under_another_parent(): void
    {
        $aisleOne = $this->nodes->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $aisleTwo = $this->nodes->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Aisle, 'Aisle 2', 'A2');
        $this->nodes->createNode($this->tenant->id, $this->location->id, $aisleTwo->id, LocationNodeType::Rack, 'Rack 2', 'R2');

        $upload = $this->upload(
            "name,sku,location_code,placement_path\nConflict,IMP-PLC-5,MAIN,A1 > R2",
            [
                'placement_mode' => 'auto_create',
                'placement_node_types' => ['aisle', 'rack'],
            ],
        );
        $upload->assertCreated();

        $preview = $this->actingAs($this->user)->getJson('/api/v1/imports/'.$upload->json('data.id').'/preview');
        $preview->assertOk();
        $preview->assertJsonPath('data.rows.0.is_valid', false);
        $this->assertStringContainsString(
            "Placement code 'R2' already exists at 'A2/R2'",
            (string) $preview->json('data.rows.0.errors.placement_path.0'),
        );
        $this->assertSame(3, LocationNode::query()->count());
        $this->assertSame($aisleOne->id, LocationNode::query()->where('code', 'A1')->value('id'));
    }

    public function test_commit_rejects_tree_drift_after_preview_without_partial_product_write(): void
    {
        $aisle = $this->nodes->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $rack = $this->nodes->createNode($this->tenant->id, $this->location->id, $aisle->id, LocationNodeType::Rack, 'Rack 2', 'R2');

        $upload = $this->upload("name,sku,location_code,placement_path\nDrift Guard,IMP-PLC-4,MAIN,A1 > R2");
        $upload->assertCreated();
        $jobId = (string) $upload->json('data.id');

        $this->actingAs($this->user)->getJson("/api/v1/imports/{$jobId}/preview")->assertOk();
        $this->nodes->updateNode($rack, ['code' => 'RX']);

        $execute = $this->actingAs($this->user)->postJson("/api/v1/imports/{$jobId}/execute");
        $execute->assertOk();
        $execute->assertJsonPath('import_result.execution_error_count', 1);

        $this->assertDatabaseMissing('products', ['sku' => 'IMP-PLC-4']);
        $this->assertSame(0, ProductPlacement::query()->count());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return TestResponse<Response>
     */
    private function upload(string $csv, array $options = []): TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/imports', [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
            'type' => 'products',
            'options' => $options,
        ]);
    }
}
