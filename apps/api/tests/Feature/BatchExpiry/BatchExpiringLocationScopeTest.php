<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\Company\Domain\Location;

require_once __DIR__.'/BatchReadLocationScopeTest.php';

final class BatchExpiringLocationScopeTest extends BatchPermissionFixture
{
    public function test_expiring_validates_uuid_and_filters_loaded_stock(): void
    {
        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '99.0000');
        $this->stockAt($batch, $this->location, '2.0000');
        $this->restrict([$this->location->id]);
        $this->getJson('/api/v1/batches/expiring?location_id=not-a-uuid')->assertUnprocessable();
        $response = $this->getJson('/api/v1/batches/expiring')->assertOk();
        self::assertSame([$this->location->id], array_column($response->json('data.0.batch_stock'), 'location_id'));
        $response->assertJsonPath('data.0.total_quantity', '2.0000');
    }

    public function test_empty_membership_scope_returns_no_expiring_lots(): void
    {
        $this->stockAt($this->lot(), $this->location, '3.0000');
        $this->restrict([]);
        $this->getJson('/api/v1/batches/expiring')->assertOk()->assertJsonPath('data', []);
    }
}
