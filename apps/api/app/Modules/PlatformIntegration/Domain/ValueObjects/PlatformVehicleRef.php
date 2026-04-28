<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Domain\ValueObjects;

/**
 * Typed read-only projection of a platform catalog vehicle.
 *
 * Mirrors the subset of TecDoc vehicle fields that downstream modules
 * (notably Workshop/Bundle) need to resolve vehicle applicability and
 * render the vehicle label. `platform_vehicle_id` matches the UUID
 * shape used by `automotive_product_vehicles.platform_vehicle_id`.
 */
final readonly class PlatformVehicleRef
{
    public function __construct(
        public string $platform_vehicle_id,
        public string $vehicle_type,
        public string $display,
        public ?string $manufacturer,
        public ?string $model_name,
        public ?int $year_from,
        public ?int $year_to,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            platform_vehicle_id: (string) ($data['vehicle_id'] ?? $data['platform_vehicle_id'] ?? ''),
            vehicle_type: (string) ($data['vehicle_type'] ?? ''),
            display: (string) ($data['display'] ?? $data['display_string'] ?? ''),
            manufacturer: isset($data['manufacturer']) ? (string) $data['manufacturer'] : null,
            model_name: isset($data['model_name']) ? (string) $data['model_name'] : null,
            year_from: isset($data['year_from']) ? (int) $data['year_from'] : null,
            year_to: isset($data['year_to']) ? (int) $data['year_to'] : null,
        );
    }
}
