<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Domain\ValueObjects;

final readonly class VinDecodeResult
{
    public function __construct(
        public string $vin,
        public ?string $licensePlate,
        public string $brand,
        public string $model,
        public ?int $year,
        public ?string $engineCode,
        public ?string $fuelType,
        public ?string $bodyType,
        public ?int $powerKw,
        public ?int $capacityCc,
        public ?string $transmission,
        public string $countryCode,
        public string $resolvedVia,
        public int $confidence,
    ) {}
}
