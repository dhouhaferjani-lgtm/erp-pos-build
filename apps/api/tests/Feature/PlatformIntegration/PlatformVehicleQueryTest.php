<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformIntegration;

use App\Modules\PlatformIntegration\Application\Contracts\PlatformVehicleQueryInterface;
use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformVehicleRef;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PlatformVehicleQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Make sure the internal platform cache does not leak between tests.
        Cache::flush();
    }

    public function test_find_vehicle_returns_typed_ref_wrapping_catalog_browse_service(): void
    {
        // Platform API returns `{ data: { ... } }`; PlatformHttpClient unwraps
        // to the `data` payload, which CatalogBrowseService passes through.
        Http::fake([
            '*/api/v1/automotive/vehicles/pc/veh-uuid-123*' => Http::response([
                'data' => [
                    'vehicle_id' => 'veh-uuid-123',
                    'vehicle_type' => 'pc',
                    'display' => 'Peugeot 308 1.6 HDi',
                    'manufacturer' => 'Peugeot',
                    'model_name' => '308',
                    'year_from' => 2013,
                    'year_to' => 2021,
                ],
            ], 200),
        ]);

        /** @var PlatformVehicleQueryInterface $query */
        $query = $this->app->make(PlatformVehicleQueryInterface::class);

        $ref = $query->findVehicle('veh-uuid-123', 'pc');

        $this->assertInstanceOf(PlatformVehicleRef::class, $ref);
        $this->assertSame('veh-uuid-123', $ref->platform_vehicle_id);
        $this->assertSame('pc', $ref->vehicle_type);
        $this->assertSame('Peugeot 308 1.6 HDi', $ref->display);
        $this->assertSame('Peugeot', $ref->manufacturer);
        $this->assertSame('308', $ref->model_name);
        $this->assertSame(2013, $ref->year_from);
        $this->assertSame(2021, $ref->year_to);

        // Verify adapter re-ordered args: contract is (vehicleId, vehicleType);
        // upstream URL is /vehicles/{vehicleType}/{vehicleId}.
        Http::assertSent(
            fn ($request): bool => str_contains($request->url(), '/automotive/vehicles/pc/veh-uuid-123')
        );
    }

    public function test_find_vehicle_returns_null_when_upstream_payload_is_empty(): void
    {
        // Platform HTTP client unwraps `data` and returns null when 'data' is
        // missing/empty. Simulate that via an empty 200 response.
        Http::fake([
            '*/api/v1/automotive/vehicles/*' => Http::response(['data' => null], 200),
        ]);

        /** @var PlatformVehicleQueryInterface $query */
        $query = $this->app->make(PlatformVehicleQueryInterface::class);

        $this->assertNull($query->findVehicle('missing-id', 'pc'));
    }

    public function test_search_vehicles_returns_empty_list(): void
    {
        // Adapter does not call upstream for search yet; contract guarantees
        // an empty array response until a dedicated endpoint lands.
        Http::fake();

        /** @var PlatformVehicleQueryInterface $query */
        $query = $this->app->make(PlatformVehicleQueryInterface::class);

        $this->assertSame([], $query->searchVehicles('peugeot', 'pc', 10));
    }
}
