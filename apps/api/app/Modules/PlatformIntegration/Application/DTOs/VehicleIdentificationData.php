<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class VehicleIdentificationData extends Data
{
    /**
     * @param array<string, mixed>|null $vehicle
     * @param array<string, mixed>|null $platformMatch
     * @param array<int, array<string, mixed>>|null $candidates
     * @param array<string, string>|null $wmiHint
     */
    public function __construct(
        public string $status,
        public ?array $vehicle,
        public ?array $platformMatch,
        public ?array $candidates,
        public ?array $wmiHint,
        public ?string $message,
    ) {}
}
