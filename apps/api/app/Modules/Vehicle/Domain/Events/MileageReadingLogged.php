<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Events;

use App\Modules\Vehicle\Domain\Enums\MileageSource;

final readonly class MileageReadingLogged
{
    public function __construct(
        public string $reading_id,
        public string $vehicle_id,
        public int $mileage,
        public MileageSource $source,
        public \DateTimeImmutable $recorded_at,
    ) {}
}
