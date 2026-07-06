<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\LocationZone;
use App\Modules\Inventory\Domain\ProductZoneAssignment;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Zones are shelf/section labels per location for count scoping and product
 * placement — stock quantity never moves to zone grain (see
 * docs/superpowers/specs/2026-07-06-live-inventory-counting-design.md).
 */
final class ZoneService
{
    public function createZone(
        string $tenantId,
        string $locationId,
        string $name,
        string $code,
        int $sortOrder = 0,
        bool $isActive = true,
    ): LocationZone {
        return LocationZone::create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'name' => $name,
            'code' => $code,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateZone(LocationZone $zone, array $attributes): LocationZone
    {
        $zone->fill($attributes);
        $zone->save();

        return $zone->refresh();
    }

    public function deleteZone(LocationZone $zone): void
    {
        $zone->delete();
    }

    /**
     * Upsert a product's zone placement at a location. The unique key on
     * `product_zone_assignments` is (product_id, location_id), so reassigning
     * a product to a different zone AT THE SAME LOCATION moves the existing
     * row — it never creates a duplicate.
     *
     * Guards that the zone actually belongs to the given location: a caller
     * (e.g. Task C1) passing a locationId that does not match the zone's own
     * location would silently place the product in the wrong zone/location
     * pairing, so this is rejected instead.
     *
     * @throws InvalidArgumentException when the zone does not belong to $locationId
     */
    public function assignProduct(string $productId, string $locationId, string $zoneId): void
    {
        /** @var LocationZone $zone */
        $zone = LocationZone::query()->findOrFail($zoneId);

        if ($zone->location_id !== $locationId) {
            throw new InvalidArgumentException(
                "Zone {$zoneId} belongs to location {$zone->location_id}, not {$locationId}."
            );
        }

        ProductZoneAssignment::query()->updateOrCreate(
            [
                'product_id' => $productId,
                'location_id' => $locationId,
            ],
            [
                'tenant_id' => $zone->tenant_id,
                'zone_id' => $zoneId,
            ],
        );
    }

    /**
     * @param  list<string>  $productIds
     */
    public function bulkAssignProducts(string $zoneId, array $productIds): void
    {
        /** @var LocationZone $zone */
        $zone = LocationZone::query()->findOrFail($zoneId);

        foreach ($productIds as $productId) {
            $this->assignProduct($productId, $zone->location_id, $zoneId);
        }
    }

    /**
     * @return Collection<int, ProductZoneAssignment>
     */
    public function listZoneProducts(string $zoneId): Collection
    {
        return ProductZoneAssignment::query()
            ->where('zone_id', $zoneId)
            ->with('product')
            ->get();
    }
}
