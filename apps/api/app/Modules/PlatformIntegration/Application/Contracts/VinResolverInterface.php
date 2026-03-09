<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Contracts;

use App\Modules\PlatformIntegration\Domain\ValueObjects\VinDecodeResult;

interface VinResolverInterface
{
    public function supports(string $countryCode): bool;

    public function resolveByVin(string $vin): ?VinDecodeResult;

    public function resolveByPlate(string $plate, string $countryCode): ?VinDecodeResult;
}
