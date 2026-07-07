<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SeedsPlacementFixtures;

final class PlacementDeltaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPlacementFixtures;

    private User $user;

    private Location $location;

    private LocationNode $node;

    private LocationNodeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTenantAndCompany();
        $this->user = $this->seedPermissionedUser(['inventory.view']);
        $this->location = $this->seedLocationForCompany();
        $this->service = app(LocationNodeService::class);
        $this->node = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Zone A', 'ZA');
    }

    /**
     * @return list<string> placement ids in seeded order
     */
    private function seedPlacements(int $count, Carbon $base): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $product = $this->seedProductForCompany("DLT-P{$i}", "Delta Product {$i}");
            $placement = $this->service->assignProduct($this->tenant->id, $product->id, $this->location->id, $this->node->id);
            // controlled updated_at; two rows share a timestamp to exercise the id tiebreaker
            $ts = $base->copy()->addSeconds(intdiv($i, 2));
            DB::table('product_placements')->where('id', $placement->id)->update([
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
            $ids[] = $placement->id;
        }

        return $ids;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, hwm: string, pages: int}
     */
    private function drainDelta(int $limit, ?string $hwm = null): array
    {
        $rows = [];
        $cursor = null;
        $pages = 0;

        do {
            $params = ['location_id' => $this->location->id, 'limit' => (string) $limit];
            if ($cursor !== null) {
                $params['cursor'] = $cursor['updated_at'].'|'.$cursor['id'];
            }
            if ($hwm !== null) {
                $params['sync_high_watermark'] = $hwm;
            }

            $response = $this->actingAs($this->user)->getJson('/api/v1/inventory/placements?'.http_build_query($params));
            $response->assertStatus(200);

            $hwm = (string) $response->json('sync_high_watermark');
            $pageRows = $response->json('data');
            $this->assertLessThanOrEqual($limit, count($pageRows));
            $rows = array_merge($rows, $pageRows);
            $cursor = $response->json('next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 20);

        return ['rows' => $rows, 'hwm' => $hwm, 'pages' => $pages];
    }

    public function test_paginates_without_duplicates_in_stable_order(): void
    {
        $this->seedPlacements(5, Carbon::parse('2026-07-07 08:00:00', 'UTC'));

        $result = $this->drainDelta(limit: 2);

        $this->assertCount(5, $result['rows']);
        $this->assertGreaterThanOrEqual(3, $result['pages']);

        $ids = array_column($result['rows'], 'id');
        $this->assertSame($ids, array_unique($ids), 'no duplicates across pages');

        // stable (updated_at, id) ascending order
        $tuples = array_map(
            static fn (array $row): string => $row['updated_at'].'|'.$row['id'],
            $result['rows'],
        );
        $sorted = $tuples;
        sort($sorted);
        $this->assertSame($sorted, $tuples, 'rows ordered by (updated_at, id)');
    }

    public function test_tombstoned_rows_appear_in_delta_with_deleted_at(): void
    {
        $product = $this->seedProductForCompany('DLT-TOMB', 'Delta Tombstone');
        $this->service->assignProduct($this->tenant->id, $product->id, $this->location->id, $this->node->id);
        $this->service->unassignProduct($product->id, $this->location->id);

        $result = $this->drainDelta(limit: 10);

        $this->assertCount(1, $result['rows']);
        $this->assertNotNull($result['rows'][0]['deleted_at']);
        $this->assertSame($product->id, $result['rows'][0]['product_id']);
    }

    public function test_row_updated_exactly_at_prior_watermark_is_caught_next_run(): void
    {
        $base = Carbon::parse('2026-07-07 08:00:00', 'UTC');
        $this->seedPlacements(2, $base);

        // run 1 with an explicit watermark
        $hwm = $base->copy()->addMinutes(5);
        $run1 = $this->drainDelta(limit: 10, hwm: $hwm->toIso8601String());
        $this->assertCount(2, $run1['rows']);
        $lastTuple = end($run1['rows']);

        // a row lands with updated_at EXACTLY the prior watermark
        $boundaryProduct = $this->seedProductForCompany('DLT-EDGE', 'Delta Edge');
        $boundary = $this->service->assignProduct($this->tenant->id, $boundaryProduct->id, $this->location->id, $this->node->id);
        DB::table('product_placements')->where('id', $boundary->id)->update([
            'created_at' => $hwm,
            'updated_at' => $hwm,
        ]);

        // run 2 resumes from run 1's final tuple with a NEW watermark:
        // (updated_at, id) > cursor catches the boundary row — nothing lost.
        $cursor = $lastTuple['updated_at'].'|'.$lastTuple['id'];
        $response = $this->actingAs($this->user)->getJson('/api/v1/inventory/placements?'.http_build_query([
            'location_id' => $this->location->id,
            'limit' => '10',
            'cursor' => $cursor,
        ]));
        $response->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($boundary->id, $ids, 'boundary row must appear on the next run');
    }

    public function test_rows_beyond_watermark_are_excluded(): void
    {
        $base = Carbon::parse('2026-07-07 08:00:00', 'UTC');
        $this->seedPlacements(2, $base);

        // a row updated AFTER the watermark must not leak into this run
        $lateProduct = $this->seedProductForCompany('DLT-LATE', 'Delta Late');
        $late = $this->service->assignProduct($this->tenant->id, $lateProduct->id, $this->location->id, $this->node->id);
        DB::table('product_placements')->where('id', $late->id)->update([
            'created_at' => $base->copy()->addHour(),
            'updated_at' => $base->copy()->addHour(),
        ]);

        $result = $this->drainDelta(limit: 10, hwm: $base->copy()->addMinutes(5)->toIso8601String());

        $ids = array_column($result['rows'], 'id');
        $this->assertNotContains($late->id, $ids);
        $this->assertCount(2, $result['rows']);
    }

    public function test_invalid_location_id_returns_422(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/inventory/placements?location_id=not-a-uuid')
            ->assertStatus(422);
    }

    public function test_foreign_company_location_returns_404(): void
    {
        $foreignLocationId = $this->location->id;
        $this->seedTenantAndCompany(); // switch context to a fresh tenant+company
        $otherUser = $this->seedPermissionedUser(['inventory.view']);

        $this->actingAs($otherUser)
            ->getJson('/api/v1/inventory/placements?location_id='.$foreignLocationId)
            ->assertStatus(404);
    }
}
