<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\Services\LocationNodeService;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Presentation\Requests\CreateNodeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use Tests\Traits\SeedsPlacementFixtures;

final class NodeRequestValidationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPlacementFixtures;

    private Location $location;

    private LocationNodeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTenantAndCompany();
        $this->location = $this->seedLocationForCompany();
        $this->service = app(LocationNodeService::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private function validateCreate(array $payload): array
    {
        $request = new CreateNodeRequest(app(CompanyContext::class));
        $request->merge($payload);

        return Validator::make($payload, $request->rules())->errors()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'location_id' => $this->location->id,
            'node_type' => 'aisle',
            'name' => 'Aisle 1',
            'code' => 'A1',
        ];
    }

    public function test_valid_payload_passes(): void
    {
        $this->assertSame([], $this->validateCreate($this->validPayload()));
    }

    public function test_code_with_slash_fails_regex(): void
    {
        $errors = $this->validateCreate(['code' => 'A/1'] + $this->validPayload());
        $this->assertArrayHasKey('code', $errors);
    }

    public function test_code_with_like_wildcards_fails_regex(): void
    {
        foreach (['A%1', 'A_1', 'A 1'] as $bad) {
            $errors = $this->validateCreate(['code' => $bad] + $this->validPayload());
            $this->assertArrayHasKey('code', $errors, "code '$bad' should fail");
        }
    }

    public function test_duplicate_live_code_fails(): void
    {
        $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');

        $errors = $this->validateCreate($this->validPayload());
        $this->assertArrayHasKey('code', $errors);
    }

    public function test_tombstoned_code_is_reusable(): void
    {
        $node = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Aisle, 'Aisle 1', 'A1');
        $this->service->softDeleteSubtree($node);

        $this->assertSame([], $this->validateCreate($this->validPayload()));
    }

    public function test_invalid_node_type_fails(): void
    {
        $errors = $this->validateCreate(['node_type' => 'warehouse'] + $this->validPayload());
        $this->assertArrayHasKey('node_type', $errors);
    }

    public function test_parent_must_be_live_node_in_same_location(): void
    {
        $other = $this->seedLocationForCompany('WH-VAL-02', 'Other Warehouse');
        $foreignParent = $this->service->createNode($this->tenant->id, $other->id, null, LocationNodeType::Zone, 'Foreign', 'F1');

        $errors = $this->validateCreate(['parent_id' => $foreignParent->id] + $this->validPayload());
        $this->assertArrayHasKey('parent_id', $errors);

        $tombstoned = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Dead', 'D1');
        $this->service->softDeleteSubtree($tombstoned);

        $errors = $this->validateCreate(['parent_id' => $tombstoned->id] + $this->validPayload());
        $this->assertArrayHasKey('parent_id', $errors);

        $liveParent = $this->service->createNode($this->tenant->id, $this->location->id, null, LocationNodeType::Zone, 'Live', 'L1');
        $this->assertSame([], $this->validateCreate(['parent_id' => $liveParent->id] + $this->validPayload()));
    }

    public function test_location_from_other_company_fails(): void
    {
        $errors = $this->validateCreate(['location_id' => '11111111-1111-1111-1111-111111111111'] + $this->validPayload());
        $this->assertArrayHasKey('location_id', $errors);
    }
}
